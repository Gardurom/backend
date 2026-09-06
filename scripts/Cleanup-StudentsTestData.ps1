$ErrorActionPreference = "Stop"

$backendUrl = "http://127.0.0.1:8000"

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path

$stateFile = Join-Path `
    $scriptRoot `
    ".test-state\students.json"

Write-Host ""
Write-Host "========================================"
Write-Host " CONTROL ESCOLAR - LIMPIEZA API"
Write-Host " MODULO: ALUMNOS"
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
        "No existe archivo de estado de Students." `
        -ForegroundColor Yellow

    Write-Host `
        "No hay datos registrados por este paquete para limpiar."

    Write-Host ""
    Write-Host "LIMPIEZA: NO REQUERIDA" -ForegroundColor Green
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

if ($state.module -ne "students") {
    throw @"
El archivo de estado no pertenece al modulo Students.

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
El archivo de estado no contiene el marcador de seguridad esperado:

test.created_by_api_test = true

No se eliminara ningun registro.
"@
}

$campusId = [string]$state.campus.id
$studentId = [string]$state.student.id
$enrollmentNumber = [string] `
    $state.student.enrollment_number

if ([string]::IsNullOrWhiteSpace($campusId)) {
    throw @"
El estado no contiene campus.id.

No se eliminara ningun registro.
"@
}

if ([string]::IsNullOrWhiteSpace($studentId)) {
    throw @"
El estado no contiene student.id.

No se eliminara ningun registro.
"@
}

if (
    [string]::IsNullOrWhiteSpace(
        $enrollmentNumber
    )
) {
    throw @"
El estado no contiene student.enrollment_number.

No se eliminara ningun registro.
"@
}

Write-Host "   OK"
Write-Host "   Campus ID:  $campusId"
Write-Host "   Student ID: $studentId"
Write-Host "   Matricula:  $enrollmentNumber"
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

Write-Host "3. Verificando alumno antes de eliminar..."

$studentAlreadyAbsent = $false
$studentBeforeDelete = $null

try {
    $studentBeforeDelete = Invoke-RestMethod `
        -Uri "$backendUrl/api/students/$studentId" `
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
        $studentAlreadyAbsent = $true
    }
    else {
        throw
    }
}

if ($studentAlreadyAbsent) {
    Write-Host `
        "   El alumno ya no existe en la API." `
        -ForegroundColor Yellow

    Write-Host `
        "   HTTP 404 confirmado."
}
else {
    if (-not $studentBeforeDelete.data) {
        throw @"
La API no devolvio data para el alumno.

No se eliminara ningun registro.
"@
    }

    if (
        [string]$studentBeforeDelete.data.id `
            -ne $studentId
    ) {
        throw @"
El ID devuelto por la API no coincide con el estado.

Esperado:
$studentId

Recibido:
$($studentBeforeDelete.data.id)

No se eliminara ningun registro.
"@
    }

    if (
        [string]$studentBeforeDelete.data.enrollment_number `
            -ne $enrollmentNumber
    ) {
        throw @"
La matricula del alumno no coincide con el estado.

Esperado:
$enrollmentNumber

Recibido:
$($studentBeforeDelete.data.enrollment_number)

No se eliminara ningun registro.
"@
    }

    if (
        [string]$studentBeforeDelete.data.campus_id `
            -ne $campusId
    ) {
        throw @"
El plantel del alumno no coincide con el estado.

Esperado:
$campusId

Recibido:
$($studentBeforeDelete.data.campus_id)

No se eliminara ningun registro.
"@
    }

    Write-Host "   OK"
    Write-Host "   ID, matricula y plantel coinciden."
}

Write-Host ""

if (-not $studentAlreadyAbsent) {
    Write-Host "4. Eliminando alumno temporal..."

    Remove-Variable deleteResponse `
        -ErrorAction SilentlyContinue

    $deleteResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/students/$studentId" `
        -Method Delete `
        -WebSession $global:webSession `
        -Headers $headers

    Write-Host "   OK"
    Write-Host "   DELETE procesado."
}
else {
    Write-Host "4. Eliminando alumno temporal..."
    Write-Host `
        "   Omitido: el alumno ya estaba ausente." `
        -ForegroundColor Yellow
}

Write-Host ""

Write-Host "5. Confirmando eliminacion mediante HTTP 404..."

$notFoundConfirmed = $false

try {
    Invoke-RestMethod `
        -Uri "$backendUrl/api/students/$studentId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $headers |
        Out-Null

    throw @"
La API aun devuelve el alumno despues del DELETE.

Student ID:
$studentId

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
            "La API aun devuelve el alumno*"
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
Write-Host " RESULTADO: CLEANUP STUDENTS OK"
Write-Host "========================================"
Write-Host ""
Write-Host "Modulo:                  STUDENTS"
Write-Host "Student ID:              $studentId"
Write-Host "Matricula:               $enrollmentNumber"
Write-Host "Eliminacion API:         OK"
Write-Host "HTTP 404 confirmado:     OK"
Write-Host "Archivo de estado:       ELIMINADO"
Write-Host ""
Write-Host "No se buscaron registros por nombre."
Write-Host "Solo se utilizo el UUID guardado en students.json."
Write-Host ""