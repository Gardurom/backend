$ErrorActionPreference = "Stop"

$backendUrl = "http://127.0.0.1:8000"

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path

$stateFile = Join-Path `
    $scriptRoot `
    ".test-state\assessments.json"

function Get-HttpStatusCode {
    param(
        [Parameter(Mandatory = $true)]
        $ErrorRecord
    )

    if (
        $ErrorRecord.Exception.Response `
        -and $ErrorRecord.Exception.Response.StatusCode
    ) {
        return [int]$ErrorRecord.Exception.Response.StatusCode
    }

    return $null
}

function Get-ErrorResponseBody {
    param(
        [Parameter(Mandatory = $true)]
        $ErrorRecord
    )

    try {
        $response = $ErrorRecord.Exception.Response

        if ($null -eq $response) {
            return $ErrorRecord.Exception.Message
        }

        $reader = New-Object System.IO.StreamReader(
            $response.GetResponseStream()
        )

        $body = $reader.ReadToEnd()
        $reader.Dispose()

        return $body
    }
    catch {
        return $ErrorRecord.Exception.Message
    }
}

Write-Host ""
Write-Host "========================================"
Write-Host " CONTROL ESCOLAR - CLEANUP API"
Write-Host " MODULO: EVALUACIONES"
Write-Host "========================================"
Write-Host ""

if (-not $global:webSession) {
    throw @"
No existe una sesion HTTP autenticada.

Ejecuta primero:

. .\scripts\Test-Auth.ps1
"@
}

if (-not $global:authHeaders) {
    throw @"
No existen encabezados autenticados.

Ejecuta primero:

. .\scripts\Test-Auth.ps1
"@
}

if (-not (Test-Path -LiteralPath $stateFile)) {
    Write-Host "No existe archivo de estado."
    Write-Host ""
    Write-Host "No hay cleanup pendiente para Assessments."
    Write-Host ""
    Write-Host "========================================"
    Write-Host " RESULTADO: CLEANUP ASSESSMENTS NO REQUERIDO"
    Write-Host "========================================"
    Write-Host ""

    return
}

Write-Host "1. Cargando archivo de estado..."

$stateJson = [System.IO.File]::ReadAllText(
    $stateFile
)

$state = $stateJson |
    ConvertFrom-Json

if (
    [string]$state.module `
        -ne "assessments"
) {
    throw @"
El archivo de estado no pertenece al modulo Assessments.

Modulo encontrado:
$($state.module)
"@
}

if (
    $state.test.created_by_api_test `
        -ne $true
) {
    throw @"
El archivo de estado no esta marcado como temporal.

Cleanup cancelado por seguridad.
"@
}

$campusId = [string]$state.campus.id

$teachingAssignmentId = [string](
    $state.dependencies.teaching_assignment.id
)

$gradingPeriodId = [string](
    $state.dependencies.grading_period.id
)

$assessmentId = [string](
    $state.assessment.id
)

if ([string]::IsNullOrWhiteSpace($campusId)) {
    throw "El estado no contiene Campus ID."
}

if (
    [string]::IsNullOrWhiteSpace(
        $teachingAssignmentId
    )
) {
    throw "El estado no contiene Teaching Assignment ID."
}

if (
    [string]::IsNullOrWhiteSpace(
        $gradingPeriodId
    )
) {
    throw "El estado no contiene Grading Period ID."
}

if (
    [string]::IsNullOrWhiteSpace(
        $assessmentId
    )
) {
    throw "El estado no contiene Assessment ID."
}

if (
    $state.dependencies.teaching_assignment.created_by_api_test `
        -ne $false
) {
    throw @"
La asignacion docente aparece marcada como temporal.

Cleanup cancelado por seguridad.
"@
}

if (
    $state.dependencies.grading_period.created_by_api_test `
        -ne $false
) {
    throw @"
El periodo de calificacion aparece marcado como temporal.

Cleanup cancelado por seguridad.
"@
}

Write-Host "   OK"
Write-Host "   Campus ID:              $campusId"
Write-Host "   Assessment ID:          $assessmentId"
Write-Host "   Teaching Assignment ID: $teachingAssignmentId"
Write-Host "   Grading Period ID:      $gradingPeriodId"
Write-Host ""

$assessmentHeaders = $global:authHeaders.Clone()

$assessmentHeaders["Accept"] = "application/json"
$assessmentHeaders["X-Campus-ID"] = $campusId

Write-Host "2. Verificando sesion autenticada..."

Remove-Variable currentUser `
    -ErrorAction SilentlyContinue

$currentUser = Invoke-RestMethod `
    -Uri "$backendUrl/api/auth/me" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $global:authHeaders

if (-not $currentUser.user) {
    throw "La API no devolvio el usuario autenticado."
}

Write-Host "   OK"
Write-Host "   Usuario: $($currentUser.user.email)"
Write-Host ""

Write-Host "3. Verificando evaluacion exacta antes de eliminar..."

$assessmentAlreadyDeleted = $false

try {
    Remove-Variable assessmentResponse `
        -ErrorAction SilentlyContinue

    $assessmentResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/assessments/$assessmentId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $assessmentHeaders
}
catch {
    $statusCode = Get-HttpStatusCode `
        -ErrorRecord $_

    if ($statusCode -eq 404) {
        $assessmentAlreadyDeleted = $true
    }
    else {
        Write-Host (
            Get-ErrorResponseBody `
                -ErrorRecord $_
        ) -ForegroundColor Red

        throw
    }
}

if (-not $assessmentAlreadyDeleted) {
    if (-not $assessmentResponse.data) {
        throw "La API no devolvio la evaluacion."
    }

    if (
        [string]$assessmentResponse.data.id `
            -ne $assessmentId
    ) {
        throw @"
El Assessment ID no coincide.

Cleanup cancelado por seguridad.
"@
    }

    if (
        [string]$assessmentResponse.data.teaching_assignment.id `
            -ne $teachingAssignmentId
    ) {
        throw @"
El Teaching Assignment ID no coincide.

Cleanup cancelado por seguridad.
"@
    }

    if (
        [string]$assessmentResponse.data.grading_period.id `
            -ne $gradingPeriodId
    ) {
        throw @"
El Grading Period ID no coincide.

Cleanup cancelado por seguridad.
"@
    }

    Write-Host "   OK"
    Write-Host "   UUID y dependencias confirmados."
}
else {
    Write-Host "   La evaluacion ya no existe."
    Write-Host "   HTTP 404 confirmado."
}

Write-Host ""

if (-not $assessmentAlreadyDeleted) {
    Write-Host "4. Eliminando evaluacion temporal..."

    Invoke-RestMethod `
        -Uri "$backendUrl/api/assessments/$assessmentId" `
        -Method Delete `
        -WebSession $global:webSession `
        -Headers $assessmentHeaders |
        Out-Null

    Write-Host "   OK"
    Write-Host ""
}
else {
    Write-Host "4. Eliminacion no requerida."
    Write-Host ""
}

Write-Host "5. Confirmando HTTP 404 de la evaluacion..."

$assessmentNotFoundConfirmed = $false

try {
    Remove-Variable verifyAssessmentResponse `
        -ErrorAction SilentlyContinue

    $verifyAssessmentResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/assessments/$assessmentId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $assessmentHeaders

    throw "La evaluacion todavia puede consultarse."
}
catch {
    $statusCode = Get-HttpStatusCode `
        -ErrorRecord $_

    if ($statusCode -eq 404) {
        $assessmentNotFoundConfirmed = $true
    }
    elseif (
        $_.Exception.Message -like `
            "*todavia puede consultarse*"
    ) {
        throw
    }
    else {
        Write-Host (
            Get-ErrorResponseBody `
                -ErrorRecord $_
        ) -ForegroundColor Red

        throw
    }
}

if (-not $assessmentNotFoundConfirmed) {
    throw "No fue posible confirmar la eliminacion de la evaluacion."
}

Write-Host "   OK"
Write-Host "   HTTP: 404"
Write-Host ""

Write-Host "6. Confirmando que la asignacion docente siga intacta..."

Remove-Variable assignmentResponse `
    -ErrorAction SilentlyContinue

$assignmentResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/teaching-assignments/$teachingAssignmentId" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $assessmentHeaders

if (-not $assignmentResponse.data) {
    throw "La asignacion docente existente ya no puede consultarse."
}

if (
    [string]$assignmentResponse.data.id `
        -ne $teachingAssignmentId
) {
    throw "La API devolvio otra asignacion docente."
}

Write-Host "   OK"
Write-Host ""

Write-Host "7. Eliminando archivo de estado..."

Remove-Item `
    -LiteralPath $stateFile `
    -Force

if (Test-Path -LiteralPath $stateFile) {
    throw @"
La evaluacion fue eliminada,
pero no fue posible eliminar el archivo de estado:

$stateFile
"@
}

Write-Host "   OK"
Write-Host ""

Write-Host "========================================"
Write-Host " RESULTADO: CLEANUP ASSESSMENTS OK" `
    -ForegroundColor Green
Write-Host "========================================"
Write-Host ""
Write-Host "Assessment ID:               $assessmentId"
Write-Host "Teaching Assignment ID:      $teachingAssignmentId"
Write-Host "Grading Period ID:           $gradingPeriodId"
Write-Host ""
Write-Host "Evaluacion eliminada:        OK"
Write-Host "Assessment HTTP 404:         OK"
Write-Host "Asignacion docente intacta:  OK"
Write-Host "Periodo no modificado:       OK"
Write-Host "Archivo de estado:           ELIMINADO"
Write-Host ""