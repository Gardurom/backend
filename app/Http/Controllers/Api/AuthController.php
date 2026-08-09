<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const LOCK_MINUTES = 15;

    /*
     * Hash válido usado cuando la cuenta no existe para reducir
     * diferencias temporales que permitan enumerar usuarios.
     */
    private const DUMMY_PASSWORD_HASH =
        '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

    public function login(LoginRequest $request): JsonResponse
    {
        $email = Str::lower($request->string('email')->toString());
        $password = $request->string('password')->toString();
        $rateLimitKey = $this->rateLimitKey($email, $request->ip());

        if (RateLimiter::tooManyAttempts(
            $rateLimitKey,
            self::MAX_ATTEMPTS
        )) {
            return response()->json([
                'message' => 'Demasiados intentos. Intenta nuevamente más tarde.',
                'retry_after_seconds' => RateLimiter::availableIn(
                    $rateLimitKey
                ),
            ], 429);
        }

        $user = User::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->first();

        $passwordIsValid = $user
            ? Hash::check($password, $user->password)
            : Hash::check($password, self::DUMMY_PASSWORD_HASH);

        $isLocked = $user?->locked_until?->isFuture() ?? false;

        if (
            ! $user
            || ! $user->is_active
            || $isLocked
            || ! $passwordIsValid
        ) {
            RateLimiter::hit($rateLimitKey, 60);

            if (
                $user
                && $user->is_active
                && ! $isLocked
                && ! $passwordIsValid
            ) {
                $this->registerFailedAttempt($user);
            }

            throw ValidationException::withMessages([
                'email' => [
                    'Las credenciales no son válidas o la cuenta no está disponible.',
                ],
            ]);
        }

        RateLimiter::clear($rateLimitKey);

        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        Auth::guard('web')->login(
            $user,
            $request->boolean('remember')
        );

        $request->session()->regenerate();

        return response()->json([
            'message' => 'Sesión iniciada correctamente.',
            'user' => $this->userPayload($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }

    private function registerFailedAttempt(User $user): void
    {
        DB::transaction(function () use ($user): void {
            /** @var User|null $lockedUser */
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedUser) {
                return;
            }

            $attempts = min(
                100,
                $lockedUser->failed_login_attempts + 1
            );

            $values = [
                'failed_login_attempts' => $attempts,
            ];

            if ($attempts >= self::MAX_ATTEMPTS) {
                $values['locked_until'] = now()->addMinutes(
                    self::LOCK_MINUTES
                );
            }

            $lockedUser->forceFill($values)->save();
        });
    }

    private function rateLimitKey(string $email, ?string $ip): string
    {
        return 'login:'.hash(
            'sha256',
            $email.'|'.($ip ?? 'unknown')
        );
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at,
            'must_change_password' => $user->must_change_password,
            'mfa_enabled' => $user->mfa_enabled,
        ];
    }
}