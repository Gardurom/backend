$ErrorActionPreference = "Stop"

$backendUrl = "http://127.0.0.1:8000"

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path

$stateFile = Join-Path `
    $scriptRoot `
    ".test-state\subjects.json"

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
Write-Host " MODULO: MATERIAS"
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
    Write-Host "No hay cleanup pendiente para Subjects."
    Write-Host ""
    Write-Host "========================================"
    Write-Host " RESULTADO: CLEANUP SUBJECTS NO REQUERIDO"
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
        -ne "subjects"
) {
    throw @"
El archivo de estado no pertenece al modulo Subjects.

Modulo encontrado:
$($state.module)
"@
}

if (
    $state.test.created_by_api_test `
        -ne $true
) {
    throw @"
El archivo de estado no esta marcado como dato temporal de prueba.

Se cancela el cleanup por seguridad.
"@
}

$campusId = [string]$state.campus.id
$subjectId = [string]$state.subject.id
$subjectCode = [string]$state.subject.code
$subjectName = [string]$state.subject.name

if (
    [string]::IsNullOrWhiteSpace(
        $campusId
    )
) {
    throw "El archivo de estado no contiene Campus ID."
}

if (
    [string]::IsNullOrWhiteSpace(
        $subjectId
    )
) {
    throw "El archivo de estado no contiene Subject ID."
}

if (
    [string]::IsNullOrWhiteSpace(
        $subjectCode
    )
) {
    throw "El archivo de estado no contiene codigo."
}

if (
    [string]::IsNullOrWhiteSpace(
        $subjectName
    )
) {
    throw "El archivo de estado no contiene nombre."
}

Write-Host "   OK"
Write-Host "   Campus ID:  $campusId"
Write-Host "   Subject ID: $subjectId"
Write-Host "   Codigo:     $subjectCode"
Write-Host ""

$subjectHeaders = $global:authHeaders.Clone()

$subjectHeaders["Accept"] = "application/json"
$subjectHeaders["X-Campus-ID"] = $campusId

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

if (
    [string]::IsNullOrWhiteSpace(
        [string]$currentUser.user.email
    )
) {
    throw "La API no devolvio el correo del usuario."
}

Write-Host "   OK"
Write-Host "   Usuario: $($currentUser.user.email)"
Write-Host ""

Write-Host "3. Verificando materia exacta antes de eliminar..."

$subjectAlreadyDeleted = $false

try {
    Remove-Variable showResponse `
        -ErrorAction SilentlyContinue

    $showResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/subjects/$subjectId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $subjectHeaders
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

if ($subjectAlreadyDeleted) {
    Write-Host "   La materia ya no existe."
    Write-Host "   HTTP 404 confirmado."
    Write-Host ""

    Write-Host "4. Eliminando archivo de estado..."

    Remove-Item `
        -LiteralPath $stateFile `
        -Force

    if (Test-Path -LiteralPath $stateFile) {
        throw "No fue posible eliminar subjects.json."
    }

    Write-Host "   OK"
    Write-Host ""

    Write-Host "========================================"
    Write-Host " RESULTADO: CLEANUP SUBJECTS OK" `
        -ForegroundColor Green
    Write-Host "========================================"
    Write-Host ""
    Write-Host "Subject ID: $subjectId"
    Write-Host "La materia ya estaba eliminada."
    Write-Host "HTTP 404 confirmado:      OK"
    Write-Host "Archivo de estado:        ELIMINADO"
    Write-Host ""

    return
}

if (-not $showResponse.data) {
    throw "La API no devolvio data para la materia."
}

if (
    [string]$showResponse.data.id `
        -ne $subjectId
) {
    throw @"
El UUID devuelto no coincide con el estado.

Esperado:
$subjectId

Recibido:
$($showResponse.data.id)

Cleanup cancelado por seguridad.
"@
}

if (
    [string]$showResponse.data.campus_id `
        -ne $campusId
) {
    throw @"
El Campus ID no coincide.

Esperado:
$campusId

Recibido:
$($showResponse.data.campus_id)

Cleanup cancelado por seguridad.
"@
}

if (
    [string]$showResponse.data.code `
        -ne $subjectCode
) {
    throw @"
El codigo no coincide con el archivo de estado.

Esperado:
$subjectCode

Recibido:
$($showResponse.data.code)

Cleanup cancelado por seguridad.
"@
}

Write-Host "   OK"
Write-Host "   UUID exacto confirmado."
Write-Host "   Campus exacto confirmado."
Write-Host "   Codigo exacto confirmado."
Write-Host ""

Write-Host "4. Eliminando materia temporal..."

Remove-Variable deleteResponse `
    -ErrorAction SilentlyContinue

$deleteResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/subjects/$subjectId" `
    -Method Delete `
    -WebSession $global:webSession `
    -Headers $subjectHeaders

Write-Host "   OK"
Write-Host ""

Write-Host "5. Confirmando HTTP 404 despues de eliminar..."

$notFoundConfirmed = $false
$notFoundStatus = $null

try {
    Remove-Variable verifyResponse `
        -ErrorAction SilentlyContinue

    $verifyResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/subjects/$subjectId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $subjectHeaders

    throw @"
La materia todavia puede consultarse despues del DELETE.

Subject ID:
$subjectId
"@
}
catch {
    $notFoundStatus = Get-HttpStatusCode `
        -ErrorRecord $_

    if ($notFoundStatus -eq 404) {
        $notFoundConfirmed = $true
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

        throw @"
Se esperaba HTTP 404 despues del DELETE.

HTTP recibido:
$notFoundStatus
"@
    }
}

if (-not $notFoundConfirmed) {
    throw "No fue posible confirmar la eliminacion."
}

Write-Host "   OK"
Write-Host "   HTTP: 404"
Write-Host ""

Write-Host "6. Eliminando archivo de estado..."

Remove-Item `
    -LiteralPath $stateFile `
    -Force

if (Test-Path -LiteralPath $stateFile) {
    throw @"
La materia fue eliminada correctamente,
pero no fue posible eliminar el archivo de estado:

$stateFile
"@
}

Write-Host "   OK"
Write-Host ""

Write-Host "========================================"
Write-Host " RESULTADO: CLEANUP SUBJECTS OK" `
    -ForegroundColor Green
Write-Host "========================================"
Write-Host ""
Write-Host "Subject ID:              $subjectId"
Write-Host "Campus ID:               $campusId"
Write-Host "Codigo:                  $subjectCode"
Write-Host "Eliminacion API:         OK"
Write-Host "HTTP 404 confirmado:     OK"
Write-Host "Archivo de estado:       ELIMINADO"
Write-Host ""