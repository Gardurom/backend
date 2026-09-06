$ErrorActionPreference = "Stop"

$backendUrl = "http://127.0.0.1:8000"

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path

$stateFile = Join-Path `
    $scriptRoot `
    ".test-state\teachers.json"

Write-Host ""
Write-Host "========================================"
Write-Host " CONTROL ESCOLAR - LIMPIEZA API"
Write-Host " MODULO: PROFESORES"
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
No existen los encabezados autenticados.

Ejecuta primero:

. .\scripts\Test-Auth.ps1
"@
}

if (-not (Test-Path -LiteralPath $stateFile)) {
    Write-Host `
        "No existe archivo de estado de Teachers." `
        -ForegroundColor Yellow

    Write-Host `
        "No hay datos registrados por este paquete para limpiar."

    Write-Host ""
    Write-Host `
        "LIMPIEZA: NO REQUERIDA" `
        -ForegroundColor Green
    Write-Host ""

    exit 0
}

Write-Host "1. Cargando archivo de estado..."

$stateJson = [System.IO.File]::ReadAllText(
    $stateFile
)

try {
    $state = $stateJson |
        ConvertFrom-Json
}
catch {
    throw @"
No fue posible interpretar:

$stateFile

El JSON del estado no es valido.

No se eliminara ningun registro.
"@
}

if ($state.module -ne "teachers") {
    throw @"
El archivo de estado no pertenece al modulo Teachers.

Modulo encontrado:
$($state.module)

No se eliminara ningun registro.
"@
}

if (
    -not $state.test `
    -or $state.test.created_by_api_test -ne $true
) {
    throw @"
El archivo de estado no contiene el marcador esperado:

test.created_by_api_test = true

No se eliminara ningun registro.
"@
}

$campusId = [string]$state.campus.id
$teacherId = [string]$state.teacher.id
$personId = [string]$state.teacher.person_id
$employeeNumber = [string] `
    $state.teacher.employee_number

if ([string]::IsNullOrWhiteSpace($campusId)) {
    throw @"
El estado no contiene campus.id.

No se eliminara ningun registro.
"@
}

if ([string]::IsNullOrWhiteSpace($teacherId)) {
    throw @"
El estado no contiene teacher.id.

No se eliminara ningun registro.
"@
}

if (
    [string]::IsNullOrWhiteSpace(
        $employeeNumber
    )
) {
    throw @"
El estado no contiene teacher.employee_number.

No se eliminara ningun registro.
"@
}

Write-Host "   OK"
Write-Host "   Campus ID:  $campusId"
Write-Host "   Teacher ID: $teacherId"
Write-Host "   Person ID:  $personId"
Write-Host "   Numero:     $employeeNumber"
Write-Host ""

$headers = $global:authHeaders.Clone()

$headers["Accept"] = "application/json"
$headers["X-Campus-ID"] = $campusId

Write-Host "2. Verificando sesion autenticada..."

$currentUser = Invoke-RestMethod `
    -Uri "$backendUrl/api/auth/me" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $headers

if (-not $currentUser.user) {
    throw @"
La API no devolvio el usuario autenticado.

No se eliminara ningun registro.
"@
}

Write-Host "   OK"
Write-Host "   Usuario: $($currentUser.user.email)"
Write-Host ""

Write-Host "3. Verificando profesor antes de eliminar..."

$teacherAlreadyAbsent = $false
$teacherBeforeDelete = $null

try {
    $teacherBeforeDelete = Invoke-RestMethod `
        -Uri "$backendUrl/api/teachers/$teacherId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $headers
}
catch {
    if (
        $_.Exception.Response `
        -and [int]$_.Exception.Response.StatusCode `
            -eq 404
    ) {
        $teacherAlreadyAbsent = $true
    }
    else {
        throw
    }
}

if ($teacherAlreadyAbsent) {
    Write-Host `
        "   El profesor ya no existe en la API." `
        -ForegroundColor Yellow

    Write-Host "   HTTP 404 confirmado."
}
else {
    if (-not $teacherBeforeDelete.data) {
        throw @"
La API no devolvio data para el profesor.

No se eliminara ningun registro.
"@
    }

    if (
        [string]$teacherBeforeDelete.data.id `
            -ne $teacherId
    ) {
        throw @"
El ID devuelto por la API no coincide con el estado.

Esperado:
$teacherId

Recibido:
$($teacherBeforeDelete.data.id)

No se eliminara ningun registro.
"@
    }

    if (
        [string]$teacherBeforeDelete.data.employee_number `
            -ne $employeeNumber
    ) {
        throw @"
El numero de empleado no coincide con el estado.

Esperado:
$employeeNumber

Recibido:
$($teacherBeforeDelete.data.employee_number)

No se eliminara ningun registro.
"@
    }

    if (
        [string]$teacherBeforeDelete.data.campus_id `
            -ne $campusId
    ) {
        throw @"
El plantel del profesor no coincide con el estado.

Esperado:
$campusId

Recibido:
$($teacherBeforeDelete.data.campus_id)

No se eliminara ningun registro.
"@
    }

    if (
        -not [string]::IsNullOrWhiteSpace(
            $personId
        ) `
        -and [string]$teacherBeforeDelete.data.person.id `
            -ne $personId
    ) {
        throw @"
El Person ID del profesor no coincide con el estado.

Esperado:
$personId

Recibido:
$($teacherBeforeDelete.data.person.id)

No se eliminara ningun registro.
"@
    }

    Write-Host "   OK"
    Write-Host `
        "   ID, numero de empleado, plantel y persona coinciden."
}

Write-Host ""

Write-Host "4. Eliminando profesor temporal..."

if (-not $teacherAlreadyAbsent) {
    Remove-Variable deleteResponse `
        -ErrorAction SilentlyContinue

    $deleteResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/teachers/$teacherId" `
        -Method Delete `
        -WebSession $global:webSession `
        -Headers $headers

    Write-Host "   OK"
    Write-Host "   DELETE procesado."
}
else {
    Write-Host `
        "   Omitido: el profesor ya estaba ausente." `
        -ForegroundColor Yellow
}

Write-Host ""

Write-Host "5. Confirmando eliminacion mediante HTTP 404..."

$notFoundConfirmed = $false

try {
    Invoke-RestMethod `
        -Uri "$backendUrl/api/teachers/$teacherId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $headers |
        Out-Null

    throw @"
La API aun devuelve el profesor despues del DELETE.

Teacher ID:
$teacherId

No se eliminara el archivo de estado.
"@
}
catch {
    if (
        $_.Exception.Response `
        -and [int]$_.Exception.Response.StatusCode `
            -eq 404
    ) {
        $notFoundConfirmed = $true
    }
    elseif (
        $_.Exception.Message -like `
            "La API aun devuelve el profesor*"
    ) {
        throw
    }
    else {
        throw
    }
}

if (-not $notFoundConfirmed) {
    throw @"
No fue posible confirmar HTTP 404.

No se eliminara el archivo de estado.
"@
}

Write-Host "   OK"
Write-Host "   HTTP 404 confirmado."
Write-Host ""

Write-Host "6. Eliminando archivo de estado..."

Remove-Item `
    -LiteralPath $stateFile `
    -Force

if (Test-Path -LiteralPath $stateFile) {
    throw @"
No fue posible eliminar:

$stateFile
"@
}

Write-Host "   OK"
Write-Host "   Estado eliminado."
Write-Host ""

Write-Host "========================================"
Write-Host " RESULTADO: CLEANUP TEACHERS OK"
Write-Host "========================================"
Write-Host ""
Write-Host "Modulo:                  TEACHERS"
Write-Host "Teacher ID:              $teacherId"
Write-Host "Person ID:               $personId"
Write-Host "Numero de empleado:      $employeeNumber"
Write-Host "Eliminacion API:         OK"
Write-Host "HTTP 404 confirmado:     OK"
Write-Host "Archivo de estado:       ELIMINADO"
Write-Host ""
Write-Host "No se buscaron registros por nombre."
Write-Host "Solo se utilizo el UUID guardado en teachers.json."
Write-Host ""