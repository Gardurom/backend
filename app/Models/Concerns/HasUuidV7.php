<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait HasUuidV7
{
    protected static function bootHasUuidV7(): void
    {
        static::creating(function (Model $model): void {
            if (! $model->getKey()) {
                $model->setAttribute(
                    $model->getKeyName(),
                    (string) Str::uuid7()
                );
            }
        });
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }
}