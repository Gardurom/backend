$ErrorActionPreference = "Stop"

$backendUrl = "http://127.0.0.1:8000"

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path

$stateFile = Join-Path `
    $scriptRoot `
    ".test-state\school-groups.json"

Write-Host ""
Write-Host "========================================"
Write-Host " CONTROL ESCOLAR - LIMPIEZA API"
Write-Host " MODULO: GRUPOS ESCOLARES"
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
        "No existe archivo de estado de SchoolGroups." `
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

if ($state.module -ne "school-groups") {
    throw @"
El archivo de estado no pertenece al modulo SchoolGroups.

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
$schoolCycleId = [string]$state.school_cycle.id
$schoolGroupId = [string]$state.group.id
$gradeLevel = [string]$state.group.grade_level
$section = [string]$state.group.section

if ([string]::IsNullOrWhiteSpace($campusId)) {
    throw @"
El estado no contiene campus.id.

No se eliminara ningun registro.
"@
}

if ([string]::IsNullOrWhiteSpace($schoolCycleId)) {
    throw @"
El estado no contiene school_cycle.id.

No se eliminara ningun registro.
"@
}

if ([string]::IsNullOrWhiteSpace($schoolGroupId)) {
    throw @"
El estado no contiene group.id.

No se eliminara ningun registro.
"@
}

if ([string]::IsNullOrWhiteSpace($gradeLevel)) {
    throw @"
El estado no contiene group.grade_level.

No se eliminara ningun registro.
"@
}

if ([string]::IsNullOrWhiteSpace($section)) {
    throw @"
El estado no contiene group.section.

No se eliminara ningun registro.
"@
}

Write-Host "   OK"
Write-Host "   Campus ID:       $campusId"
Write-Host "   School Cycle ID: $schoolCycleId"
Write-Host "   Group ID:        $schoolGroupId"
Write-Host "   Grado:           $gradeLevel"
Write-Host "   Seccion:         $section"
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

Write-Host "3. Verificando grupo antes de eliminar..."

$groupAlreadyAbsent = $false
$groupBeforeDelete = $null

try {
    $groupBeforeDelete = Invoke-RestMethod `
        -Uri "$backendUrl/api/groups/$schoolGroupId" `
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
        $groupAlreadyAbsent = $true
    }
    else {
        throw
    }
}

if ($groupAlreadyAbsent) {
    Write-Host `
        "   El grupo ya no existe en la API." `
        -ForegroundColor Yellow

    Write-Host "   HTTP 404 confirmado."
}
else {
    if (-not $groupBeforeDelete.data) {
        throw @"
La API no devolvio data para el grupo.

No se eliminara ningun registro.
"@
    }

    if (
        [string]$groupBeforeDelete.data.id `
            -ne $schoolGroupId
    ) {
        throw @"
El ID devuelto por la API no coincide con el estado.

Esperado:
$schoolGroupId

Recibido:
$($groupBeforeDelete.data.id)

No se eliminara ningun registro.
"@
    }

    if (
        [string]$groupBeforeDelete.data.school_cycle_id `
            -ne $schoolCycleId
    ) {
        throw @"
El ciclo escolar no coincide con el estado.

Esperado:
$schoolCycleId

Recibido:
$($groupBeforeDelete.data.school_cycle_id)

No se eliminara ningun registro.
"@
    }

    if (
        [string]$groupBeforeDelete.data.grade_level `
            -ne $gradeLevel
    ) {
        throw @"
El grado no coincide con el estado.

Esperado:
$gradeLevel

Recibido:
$($groupBeforeDelete.data.grade_level)

No se eliminara ningun registro.
"@
    }

    if (
        [string]$groupBeforeDelete.data.section `
            -ne $section
    ) {
        throw @"
La seccion no coincide con el estado.

Esperado:
$section

Recibido:
$($groupBeforeDelete.data.section)

No se eliminara ningun registro.
"@
    }

    Write-Host "   OK"
    Write-Host `
        "   ID, ciclo escolar, grado y seccion coinciden."
}

Write-Host ""

Write-Host "4. Eliminando grupo temporal..."

if (-not $groupAlreadyAbsent) {
    Remove-Variable deleteResponse `
        -ErrorAction SilentlyContinue

    $deleteResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/groups/$schoolGroupId" `
        -Method Delete `
        -WebSession $global:webSession `
        -Headers $headers

    Write-Host "   OK"
    Write-Host "   DELETE procesado."
}
else {
    Write-Host `
        "   Omitido: el grupo ya estaba ausente." `
        -ForegroundColor Yellow
}

Write-Host ""

Write-Host "5. Confirmando eliminacion mediante HTTP 404..."

$notFoundConfirmed = $false

try {
    Invoke-RestMethod `
        -Uri "$backendUrl/api/groups/$schoolGroupId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $headers |
        Out-Null

    throw @"
La API aun devuelve el grupo despues del DELETE.

Group ID:
$schoolGroupId

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
            "La API aun devuelve el grupo*"
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
Write-Host " RESULTADO: CLEANUP SCHOOL GROUPS OK"
Write-Host "========================================"
Write-Host ""
Write-Host "Modulo:                  SCHOOL GROUPS"
Write-Host "Group ID:                $schoolGroupId"
Write-Host "School Cycle ID:         $schoolCycleId"
Write-Host "Grado:                   $gradeLevel"
Write-Host "Seccion:                 $section"
Write-Host "Eliminacion API:         OK"
Write-Host "HTTP 404 confirmado:     OK"
Write-Host "Archivo de estado:       ELIMINADO"
Write-Host ""
Write-Host "No se buscaron registros por nombre."
Write-Host "Solo se utilizo el UUID guardado en school-groups.json."
Write-Host ""