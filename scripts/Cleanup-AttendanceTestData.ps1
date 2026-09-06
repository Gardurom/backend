#requires -Version 5.1

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

Write-Host ""
Write-Host "========================================"
Write-Host " CONTROL ESCOLAR - CLEANUP TEST DATA"
Write-Host " MODULO: ASISTENCIAS"
Write-Host "========================================"
Write-Host ""

function Fail {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Message
    )

    throw $Message
}

function Pass {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Message
    )

    Write-Host "   OK - $Message" -ForegroundColor Green
}

function Clone-Headers {
    param(
        [Parameter(Mandatory = $true)]
        [System.Collections.IDictionary] $Headers
    )

    $clone = @{}

    foreach ($key in $Headers.Keys) {
        $clone[[string] $key] = [string] $Headers[$key]
    }

    return $clone
}

function Get-HttpStatusCode {
    param(
        [Parameter(Mandatory = $true)]
        $Exception
    )

    try {
        if ($null -ne $Exception.Exception.Response) {
            return [int] $Exception.Exception.Response.StatusCode
        }
    }
    catch {
    }

    return $null
}

function Get-ErrorResponseBody {
    param(
        [Parameter(Mandatory = $true)]
        $Exception
    )

    try {
        $response = $Exception.Exception.Response

        if ($null -eq $response) {
            return $null
        }

        $stream = $response.GetResponseStream()

        if ($null -eq $stream) {
            return $null
        }

        $reader = New-Object System.IO.StreamReader($stream)

        try {
            return $reader.ReadToEnd()
        }
        finally {
            $reader.Dispose()
        }
    }
    catch {
        return $null
    }
}

function Invoke-Api {
    param(
        [Parameter(Mandatory = $true)]
        [ValidateSet("GET", "POST", "PUT", "PATCH", "DELETE")]
        [string] $Method,

        [Parameter(Mandatory = $true)]
        [string] $Uri,

        [Parameter(Mandatory = $true)]
        [hashtable] $Headers,

        [object] $Body = $null
    )

    $parameters = @{
        Method      = $Method
        Uri         = $Uri
        WebSession  = $global:webSession
        Headers     = $Headers
        ErrorAction = "Stop"
    }

    if ($null -ne $Body) {
        $json = $Body | ConvertTo-Json -Depth 20 -Compress
        $utf8Body = [System.Text.Encoding]::UTF8.GetBytes($json)

        $parameters["Body"] = $utf8Body
        $parameters["ContentType"] = "application/json; charset=utf-8"
    }

    return Invoke-RestMethod @parameters
}

function Invoke-ExpectHttpError {
    param(
        [Parameter(Mandatory = $true)]
        [ValidateSet("GET", "POST", "PUT", "PATCH", "DELETE")]
        [string] $Method,

        [Parameter(Mandatory = $true)]
        [string] $Uri,

        [Parameter(Mandatory = $true)]
        [hashtable] $Headers,

        [Parameter(Mandatory = $true)]
        [int] $ExpectedStatus
    )

    try {
        $null = Invoke-Api `
            -Method $Method `
            -Uri $Uri `
            -Headers $Headers

        Fail "Se esperaba HTTP $ExpectedStatus, pero la solicitud fue exitosa."
    }
    catch {
        if (
            $_.Exception.Message -eq
            "Se esperaba HTTP $ExpectedStatus, pero la solicitud fue exitosa."
        ) {
            throw
        }

        $actualStatus = Get-HttpStatusCode -Exception $_
        $bodyText = Get-ErrorResponseBody -Exception $_

        if ($actualStatus -ne $ExpectedStatus) {
            Fail "Se esperaba HTTP $ExpectedStatus y se obtuvo HTTP $actualStatus. Respuesta: $bodyText"
        }

        return [pscustomobject]@{
            StatusCode = $actualStatus
            Body       = $bodyText
        }
    }
}

function Get-ResponseData {
    param(
        [Parameter(Mandatory = $true)]
        $Response
    )

    if (
        $null -ne $Response -and
        $null -ne $Response.PSObject.Properties["data"]
    ) {
        return $Response.data
    }

    return $Response
}

if (
    (-not (Test-Path variable:global:webSession)) -or
    ($null -eq $global:webSession)
) {
    Fail "No existe `$global:webSession. Ejecute primero scripts\Test-Auth.ps1."
}

if (
    (-not (Test-Path variable:global:authHeaders)) -or
    ($null -eq $global:authHeaders)
) {
    Fail "No existe `$global:authHeaders. Ejecute primero scripts\Test-Auth.ps1."
}

$backendRoot = Split-Path -Parent $PSScriptRoot
$baseUrl = "http://127.0.0.1:8000"

if (
    (Test-Path variable:global:baseUrl) -and
    (-not [string]::IsNullOrWhiteSpace(
        [string] $global:baseUrl
    ))
) {
    $baseUrl = ([string] $global:baseUrl).TrimEnd("/")
}

$stateDirectory = Join-Path $PSScriptRoot ".test-state"
$statePath = Join-Path $stateDirectory "attendance.json"

if (-not (Test-Path $statePath)) {
    Fail "No existe el estado de Attendance: $statePath."
}

$state = Get-Content `
    -Raw `
    -Path $statePath |
    ConvertFrom-Json

if ([string] $state.module -ne "attendance") {
    Fail "El archivo de estado no corresponde al modulo Attendance."
}

$campusId = [string] $state.campus.id
$schoolGroupId = [string] $state.school_group.id
$teachingAssignmentId = [string] $state.teaching_assignment.id
$teacherId = [string] $state.teacher.id
$subjectId = [string] $state.subject.id
$sessionId = [string] $state.attendance_session.id
$type = [string] $state.attendance_session.type
$notes = [string] $state.attendance_session.notes
$expectedRecordCount = [int] $state.attendance_records.count

if ([string]::IsNullOrWhiteSpace($campusId)) {
    Fail "El estado no contiene campus.id."
}

if ([string]::IsNullOrWhiteSpace($sessionId)) {
    Fail "El estado no contiene attendance_session.id."
}

if ([string]::IsNullOrWhiteSpace($notes)) {
    Fail "El estado no contiene la marca notes del fixture."
}

if ($expectedRecordCount -lt 1) {
    Fail "El estado no contiene una cantidad valida de AttendanceRecords."
}

$headers = Clone-Headers -Headers $global:authHeaders
$headers["X-Campus-ID"] = $campusId

Write-Host "Fixture a eliminar:"
Write-Host "  Campus:                 $campusId"
Write-Host "  Attendance Session:     $sessionId"
Write-Host "  Attendance Records:     $expectedRecordCount"
Write-Host "  Teaching Assignment:    $teachingAssignmentId"
Write-Host ""

Write-Host "1. Verificando identidad exacta del fixture por API..." -ForegroundColor Cyan

try {
    $showResponse = Invoke-Api `
        -Method "GET" `
        -Uri "$baseUrl/api/attendance-sessions/$sessionId" `
        -Headers $headers
}
catch {
    $statusCode = Get-HttpStatusCode -Exception $_
    $body = Get-ErrorResponseBody -Exception $_

    Fail "No fue posible verificar la sesion antes del cleanup. HTTP: $statusCode. Respuesta: $body"
}

$session = Get-ResponseData -Response $showResponse

if ([string] $session.id -ne $sessionId) {
    Fail "La API devolvio una sesion distinta al UUID guardado."
}

if ([string] $session.school_group_id -ne $schoolGroupId) {
    Fail "El SchoolGroup de la sesion no coincide con el estado."
}

if (
    [string] $session.teaching_assignment_id -ne
    $teachingAssignmentId
) {
    Fail "La TeachingAssignment de la sesion no coincide con el estado."
}

if ([string] $session.recorded_by_teacher_id -ne $teacherId) {
    Fail "El Teacher de la sesion no coincide con el estado."
}

if ([string] $session.type -ne $type) {
    Fail "El type de la sesion no coincide con el estado."
}

if ([string] $session.notes -ne $notes) {
    Fail "La marca notes de la sesion no coincide con el fixture."
}

if ([string] $session.status -ne "closed") {
    Fail "La sesion no esta closed. No se realizara cleanup automatico."
}

$apiRecords = @($session.records)

if ($apiRecords.Count -ne $expectedRecordCount) {
    Fail "La cantidad de AttendanceRecords no coincide con el estado."
}

Pass "Identidad exacta confirmada; fixture closed y con $expectedRecordCount records."

Write-Host ""
Write-Host "2. Verificando dependencia academica reutilizada..." -ForegroundColor Cyan

try {
    $assignmentResponse = Invoke-Api `
        -Method "GET" `
        -Uri "$baseUrl/api/teaching-assignments/$teachingAssignmentId" `
        -Headers $headers
}
catch {
    $statusCode = Get-HttpStatusCode -Exception $_
    $body = Get-ErrorResponseBody -Exception $_

    Fail "No fue posible verificar la TeachingAssignment reutilizada. HTTP: $statusCode. Respuesta: $body"
}

$assignment = Get-ResponseData -Response $assignmentResponse

if ([string] $assignment.id -ne $teachingAssignmentId) {
    Fail "La TeachingAssignment recuperada no coincide con el estado."
}

if ([string] $assignment.school_group_id -ne $schoolGroupId) {
    Fail "La TeachingAssignment ya no apunta al SchoolGroup esperado."
}

if ([string] $assignment.teacher_id -ne $teacherId) {
    Fail "La TeachingAssignment ya no apunta al Teacher esperado."
}

if ([string] $assignment.subject_id -ne $subjectId) {
    Fail "La TeachingAssignment ya no apunta al Subject esperado."
}

Pass "TeachingAssignment y dependencias relacionadas siguen presentes."

Write-Host ""
Write-Host "3. Eliminando exclusivamente la AttendanceSession temporal..." -ForegroundColor Cyan

$tempPhpPath = Join-Path `
    $stateDirectory `
    "cleanup-attendance-fixture.php"

$phpContent = @'
<?php

declare(strict_types=1);

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use Illuminate\Contracts\Console\Kernel;

$backendRoot = dirname(__DIR__, 2);

require $backendRoot . '/vendor/autoload.php';

$app = require $backendRoot . '/bootstrap/app.php';

$app->make(Kernel::class)->bootstrap();

if ($argc !== 4) {
    fwrite(STDERR, "INVALID_ARGUMENT_COUNT\n");
    exit(40);
}

$sessionId = (string) $argv[1];
$expectedNotes = (string) $argv[2];
$expectedRecordCount = (int) $argv[3];

$session = AttendanceSession::query()->find($sessionId);

if ($session === null) {
    fwrite(STDERR, "ATTENDANCE_SESSION_NOT_FOUND\n");
    exit(41);
}

if ((string) $session->notes !== $expectedNotes) {
    fwrite(STDERR, "ATTENDANCE_SESSION_MARKER_MISMATCH\n");
    exit(42);
}

$recordCount = $session->records()->count();

if ($recordCount !== $expectedRecordCount) {
    fwrite(
        STDERR,
        "ATTENDANCE_RECORD_COUNT_MISMATCH:"
        . $recordCount
        . "\n"
    );
    exit(43);
}

$deleted = $session->delete();

if (! $deleted) {
    fwrite(STDERR, "ATTENDANCE_SESSION_DELETE_FAILED\n");
    exit(44);
}

$stillExists = AttendanceSession::query()
    ->whereKey($sessionId)
    ->exists();

if ($stillExists) {
    fwrite(STDERR, "ATTENDANCE_SESSION_STILL_EXISTS\n");
    exit(45);
}

$remainingRecords = AttendanceRecord::query()
    ->where('attendance_session_id', $sessionId)
    ->count();

if ($remainingRecords !== 0) {
    fwrite(
        STDERR,
        "ATTENDANCE_RECORDS_REMAIN:"
        . $remainingRecords
        . "\n"
    );
    exit(46);
}

fwrite(STDOUT, "ATTENDANCE_CLEANUP_ELOQUENT_OK\n");
exit(0);
'@

$utf8NoBom = New-Object System.Text.UTF8Encoding($false)

[System.IO.File]::WriteAllText(
    $tempPhpPath,
    $phpContent,
    $utf8NoBom
)

try {
    $lintOutput = & php -l $tempPhpPath 2>&1
    $lintExitCode = $LASTEXITCODE
    $lintText = ($lintOutput | Out-String).Trim()

    if ($lintExitCode -ne 0) {
        Fail "El PHP temporal no paso php -l. Salida: $lintText"
    }

    if ($lintText -notmatch "No syntax errors detected") {
        Fail "No se obtuvo confirmacion de sintaxis PHP valida. Salida: $lintText"
    }

    $cleanupOutput = & php `
        $tempPhpPath `
        $sessionId `
        $notes `
        $expectedRecordCount `
        2>&1

    $cleanupExitCode = $LASTEXITCODE
    $cleanupText = ($cleanupOutput | Out-String).Trim()

    if ($cleanupExitCode -ne 0) {
        Fail "El cleanup Eloquent fallo. ExitCode: $cleanupExitCode. Salida: $cleanupText"
    }

    if ($cleanupText -notmatch "ATTENDANCE_CLEANUP_ELOQUENT_OK") {
        Fail "PHP termino sin la confirmacion esperada. Salida: $cleanupText"
    }

    Pass "AttendanceSession eliminada y AttendanceRecords eliminados por cascada."
}
finally {
    if (Test-Path $tempPhpPath) {
        Remove-Item `
            -Path $tempPhpPath `
            -Force
    }
}

Write-Host ""
Write-Host "4. Verificando HTTP 404 para la sesion eliminada..." -ForegroundColor Cyan

$null = Invoke-ExpectHttpError `
    -Method "GET" `
    -Uri "$baseUrl/api/attendance-sessions/$sessionId" `
    -Headers $headers `
    -ExpectedStatus 404

Pass "La sesion temporal ya no existe por API."

Write-Host ""
Write-Host "5. Verificando que TeachingAssignment siga intacta..." -ForegroundColor Cyan

try {
    $assignmentAfterResponse = Invoke-Api `
        -Method "GET" `
        -Uri "$baseUrl/api/teaching-assignments/$teachingAssignmentId" `
        -Headers $headers
}
catch {
    $statusCode = Get-HttpStatusCode -Exception $_
    $body = Get-ErrorResponseBody -Exception $_

    Fail "La TeachingAssignment reutilizada no pudo verificarse despues del cleanup. HTTP: $statusCode. Respuesta: $body"
}

$assignmentAfter = Get-ResponseData -Response $assignmentAfterResponse

if ([string] $assignmentAfter.id -ne $teachingAssignmentId) {
    Fail "La TeachingAssignment reutilizada no se preservo correctamente."
}

if ([string] $assignmentAfter.school_group_id -ne $schoolGroupId) {
    Fail "El SchoolGroup reutilizado ya no coincide."
}

if ([string] $assignmentAfter.teacher_id -ne $teacherId) {
    Fail "El Teacher reutilizado ya no coincide."
}

if ([string] $assignmentAfter.subject_id -ne $subjectId) {
    Fail "El Subject reutilizado ya no coincide."
}

Pass "Las dependencias academicas reutilizadas permanecen intactas."

Write-Host ""
Write-Host "6. Eliminando archivo de estado..." -ForegroundColor Cyan

Remove-Item `
    -Path $statePath `
    -Force

if (Test-Path $statePath) {
    Fail "No fue posible eliminar el archivo de estado."
}

Pass "Estado attendance.json eliminado."

Write-Host ""
Write-Host "========================================"
Write-Host " RESULTADO: CLEANUP ATTENDANCE OK"
Write-Host "========================================"
Write-Host ""
Write-Host "Eliminado:"
Write-Host "  Attendance Session: $sessionId"
Write-Host "  Attendance Records: $expectedRecordCount"
Write-Host ""
Write-Host "Preservado:"
Write-Host "  School Group:        $schoolGroupId"
Write-Host "  Teaching Assignment: $teachingAssignmentId"
Write-Host "  Teacher:             $teacherId"
Write-Host "  Subject:             $subjectId"
Write-Host ""
Write-Host "Estado eliminado:"
Write-Host "  $statePath"
Write-Host ""
