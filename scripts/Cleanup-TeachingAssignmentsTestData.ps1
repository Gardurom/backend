$ErrorActionPreference = "Stop"

$backendUrl = "http://127.0.0.1:8000"

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path

$stateFile = Join-Path `
    $scriptRoot `
    ".test-state\teaching-assignments.json"

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
Write-Host " MODULO: ASIGNACIONES DOCENTES"
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
    Write-Host "No hay cleanup pendiente para TeachingAssignments."
    Write-Host ""
    Write-Host "========================================"
    Write-Host " RESULTADO: CLEANUP TEACHING ASSIGNMENTS NO REQUERIDO"
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
        -ne "teaching-assignments"
) {
    throw @"
El archivo de estado no pertenece al modulo TeachingAssignments.

Modulo encontrado:
$($state.module)
"@
}

if (
    $state.test.created_by_api_test `
        -ne $true
) {
    throw @"
El archivo de estado no esta marcado como dato temporal.

Cleanup cancelado por seguridad.
"@
}

$campusId = [string]$state.campus.id

$schoolGroupId = [string](
    $state.dependencies.school_group.id
)

$teacherId = [string](
    $state.dependencies.teacher.id
)

$subjectId = [string](
    $state.dependencies.subject.id
)

$subjectCode = [string](
    $state.dependencies.subject.code
)

$assignmentId = [string](
    $state.teaching_assignment.id
)

if ([string]::IsNullOrWhiteSpace($campusId)) {
    throw "El estado no contiene Campus ID."
}

if ([string]::IsNullOrWhiteSpace($schoolGroupId)) {
    throw "El estado no contiene Group ID."
}

if ([string]::IsNullOrWhiteSpace($teacherId)) {
    throw "El estado no contiene Teacher ID."
}

if ([string]::IsNullOrWhiteSpace($subjectId)) {
    throw "El estado no contiene Subject ID."
}

if ([string]::IsNullOrWhiteSpace($subjectCode)) {
    throw "El estado no contiene codigo de materia."
}

if ([string]::IsNullOrWhiteSpace($assignmentId)) {
    throw "El estado no contiene Assignment ID."
}

if (
    $state.dependencies.subject.created_by_api_test `
        -ne $true
) {
    throw @"
La materia no esta marcada como recurso temporal.

Cleanup cancelado por seguridad.
"@
}

if (
    $state.dependencies.school_group.created_by_api_test `
        -ne $false
) {
    throw @"
El grupo aparece marcado como temporal.

Cleanup cancelado por seguridad.
"@
}

if (
    $state.dependencies.teacher.created_by_api_test `
        -ne $false
) {
    throw @"
El profesor aparece marcado como temporal.

Cleanup cancelado por seguridad.
"@
}

Write-Host "   OK"
Write-Host "   Campus ID:     $campusId"
Write-Host "   Assignment ID: $assignmentId"
Write-Host "   Subject ID:    $subjectId"
Write-Host "   Group ID:      $schoolGroupId"
Write-Host "   Teacher ID:    $teacherId"
Write-Host ""

$campusHeaders = $global:authHeaders.Clone()

$campusHeaders["Accept"] = "application/json"
$campusHeaders["X-Campus-ID"] = $campusId

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

Write-Host "3. Verificando asignacion exacta antes de eliminar..."

$assignmentAlreadyDeleted = $false

try {
    Remove-Variable assignmentResponse `
        -ErrorAction SilentlyContinue

    $assignmentResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/teaching-assignments/$assignmentId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $campusHeaders
}
catch {
    $statusCode = Get-HttpStatusCode `
        -ErrorRecord $_

    if ($statusCode -eq 404) {
        $assignmentAlreadyDeleted = $true
    }
    else {
        Write-Host (
            Get-ErrorResponseBody `
                -ErrorRecord $_
        ) -ForegroundColor Red

        throw
    }
}

if (-not $assignmentAlreadyDeleted) {
    if (-not $assignmentResponse.data) {
        throw "La API no devolvio la asignacion."
    }

    if (
        [string]$assignmentResponse.data.id `
            -ne $assignmentId
    ) {
        throw @"
El Assignment ID no coincide.

Cleanup cancelado por seguridad.
"@
    }

    if (
        [string]$assignmentResponse.data.school_group_id `
            -ne $schoolGroupId
    ) {
        throw @"
El Group ID no coincide.

Cleanup cancelado por seguridad.
"@
    }

    if (
        [string]$assignmentResponse.data.teacher_id `
            -ne $teacherId
    ) {
        throw @"
El Teacher ID no coincide.

Cleanup cancelado por seguridad.
"@
    }

    if (
        [string]$assignmentResponse.data.subject_id `
            -ne $subjectId
    ) {
        throw @"
El Subject ID no coincide.

Cleanup cancelado por seguridad.
"@
    }

    Write-Host "   OK"
    Write-Host "   UUID y dependencias confirmados."
}
else {
    Write-Host "   La asignacion ya no existe."
    Write-Host "   HTTP 404 confirmado."
}

Write-Host ""

if (-not $assignmentAlreadyDeleted) {
    Write-Host "4. Eliminando asignacion temporal..."

    Invoke-RestMethod `
        -Uri "$backendUrl/api/teaching-assignments/$assignmentId" `
        -Method Delete `
        -WebSession $global:webSession `
        -Headers $campusHeaders |
        Out-Null

    Write-Host "   OK"
    Write-Host ""
}
else {
    Write-Host "4. Eliminacion de asignacion no requerida."
    Write-Host ""
}

Write-Host "5. Confirmando HTTP 404 de la asignacion..."

$assignmentNotFoundConfirmed = $false

try {
    Remove-Variable verifyAssignmentResponse `
        -ErrorAction SilentlyContinue

    $verifyAssignmentResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/teaching-assignments/$assignmentId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $campusHeaders

    throw "La asignacion todavia puede consultarse."
}
catch {
    $statusCode = Get-HttpStatusCode `
        -ErrorRecord $_

    if ($statusCode -eq 404) {
        $assignmentNotFoundConfirmed = $true
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

if (-not $assignmentNotFoundConfirmed) {
    throw "No fue posible confirmar la eliminacion de la asignacion."
}

Write-Host "   OK"
Write-Host "   HTTP: 404"
Write-Host ""

Write-Host "6. Verificando materia temporal exacta..."

$subjectAlreadyDeleted = $false

try {
    Remove-Variable subjectResponse `
        -ErrorAction SilentlyContinue

    $subjectResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/subjects/$subjectId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $campusHeaders
}
catch {
    $statusCode = Get-HttpStatusCode `
        -ErrorRecord $_

    if ($statusCode -eq 404) {
        $subjectAlreadyDeleted = $true
    }
    else {
        Write-Host (
            Get-ErrorResponseBody `
                -ErrorRecord $_
        ) -ForegroundColor Red

        throw
    }
}

if (-not $subjectAlreadyDeleted) {
    if (-not $subjectResponse.data) {
        throw "La API no devolvio la materia temporal."
    }

    if (
        [string]$subjectResponse.data.id `
            -ne $subjectId
    ) {
        throw @"
El Subject ID no coincide.

Cleanup cancelado por seguridad.
"@
    }

    if (
        [string]$subjectResponse.data.campus_id `
            -ne $campusId
    ) {
        throw @"
El Campus ID de la materia no coincide.

Cleanup cancelado por seguridad.
"@
    }

    if (
        [string]$subjectResponse.data.code `
            -ne $subjectCode
    ) {
        throw @"
El codigo de materia no coincide.

Cleanup cancelado por seguridad.
"@
    }

    Write-Host "   OK"
    Write-Host "   Materia temporal exacta confirmada."
}
else {
    Write-Host "   La materia temporal ya no existe."
    Write-Host "   HTTP 404 confirmado."
}

Write-Host ""

if (-not $subjectAlreadyDeleted) {
    Write-Host "7. Eliminando materia temporal..."

    Invoke-RestMethod `
        -Uri "$backendUrl/api/subjects/$subjectId" `
        -Method Delete `
        -WebSession $global:webSession `
        -Headers $campusHeaders |
        Out-Null

    Write-Host "   OK"
    Write-Host ""
}
else {
    Write-Host "7. Eliminacion de materia no requerida."
    Write-Host ""
}

Write-Host "8. Confirmando HTTP 404 de la materia..."

$subjectNotFoundConfirmed = $false

try {
    Remove-Variable verifySubjectResponse `
        -ErrorAction SilentlyContinue

    $verifySubjectResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/subjects/$subjectId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $campusHeaders

    throw "La materia todavia puede consultarse."
}
catch {
    $statusCode = Get-HttpStatusCode `
        -ErrorRecord $_

    if ($statusCode -eq 404) {
        $subjectNotFoundConfirmed = $true
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

if (-not $subjectNotFoundConfirmed) {
    throw "No fue posible confirmar la eliminacion de la materia."
}

Write-Host "   OK"
Write-Host "   HTTP: 404"
Write-Host ""

Write-Host "9. Eliminando archivo de estado..."

Remove-Item `
    -LiteralPath $stateFile `
    -Force

if (Test-Path -LiteralPath $stateFile) {
    throw @"
Los recursos temporales fueron eliminados,
pero no fue posible eliminar el archivo de estado:

$stateFile
"@
}

Write-Host "   OK"
Write-Host ""

Write-Host "========================================"
Write-Host " RESULTADO: CLEANUP TEACHING ASSIGNMENTS OK" `
    -ForegroundColor Green
Write-Host "========================================"
Write-Host ""
Write-Host "Assignment ID:           $assignmentId"
Write-Host "Subject ID:              $subjectId"
Write-Host "Group ID existente:      $schoolGroupId"
Write-Host "Teacher ID existente:    $teacherId"
Write-Host ""
Write-Host "Asignacion eliminada:    OK"
Write-Host "Assignment HTTP 404:     OK"
Write-Host "Materia eliminada:       OK"
Write-Host "Subject HTTP 404:        OK"
Write-Host "Grupo existente intacto: OK"
Write-Host "Profesor existente:      OK"
Write-Host "Archivo de estado:       ELIMINADO"
Write-Host ""