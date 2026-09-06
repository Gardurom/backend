$ErrorActionPreference = "Stop"

$backendUrl = "http://127.0.0.1:8000"

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path

$stateFile = Join-Path `
    $scriptRoot `
    ".test-state\school-groups.json"

Write-Host ""
Write-Host "========================================"
Write-Host " CONTROL ESCOLAR - PRUEBA API"
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
    throw @"
No existe el archivo de estado de SchoolGroups:

$stateFile

Ejecuta primero:

.\scripts\Create-SchoolGroupsTestData.ps1
"@
}

Write-Host "1. Cargando datos de prueba..."

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
"@
}

if ($state.module -ne "school-groups") {
    throw @"
El archivo de estado no pertenece al modulo SchoolGroups.

Modulo encontrado:
$($state.module)
"@
}

if (
    -not $state.test `
    -or $state.test.created_by_api_test -ne $true
) {
    throw @"
El archivo de estado no contiene el marcador esperado:

test.created_by_api_test = true

Prueba cancelada.
"@
}

$campusId = [string]$state.campus.id
$schoolCycleId = [string]$state.school_cycle.id
$schoolGroupId = [string]$state.group.id
$gradeLevel = [string]$state.group.grade_level
$section = [string]$state.group.section

if ([string]::IsNullOrWhiteSpace($campusId)) {
    throw "El estado no contiene campus.id."
}

if ([string]::IsNullOrWhiteSpace($schoolCycleId)) {
    throw "El estado no contiene school_cycle.id."
}

if ([string]::IsNullOrWhiteSpace($schoolGroupId)) {
    throw "El estado no contiene group.id."
}

if ([string]::IsNullOrWhiteSpace($gradeLevel)) {
    throw "El estado no contiene group.grade_level."
}

if ([string]::IsNullOrWhiteSpace($section)) {
    throw "El estado no contiene group.section."
}

$headers = $global:authHeaders.Clone()

$headers["Accept"] = "application/json"
$headers["X-Campus-ID"] = $campusId

Write-Host "   OK"
Write-Host "   Campus ID:       $campusId"
Write-Host "   School Cycle ID: $schoolCycleId"
Write-Host "   Group ID:        $schoolGroupId"
Write-Host "   Grado:           $gradeLevel"
Write-Host "   Seccion:         $section"
Write-Host ""

Write-Host "2. Verificando sesion autenticada..."

$currentUser = Invoke-RestMethod `
    -Uri "$backendUrl/api/auth/me" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $headers

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

Write-Host "3. Consultando grupo preparado..."

$showResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/groups/$schoolGroupId" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $headers

if (-not $showResponse.data) {
    throw "La API no devolvio data."
}

if (
    [string]$showResponse.data.id `
        -ne $schoolGroupId
) {
    throw @"
La API devolvio un grupo diferente.

Esperado:
$schoolGroupId

Recibido:
$($showResponse.data.id)
"@
}

Write-Host "   OK"
Write-Host ""

Write-Host "4. Verificando ciclo escolar..."

if (
    [string]$showResponse.data.school_cycle_id `
        -ne $schoolCycleId
) {
    throw @"
El grupo pertenece a otro ciclo escolar.

Esperado:
$schoolCycleId

Recibido:
$($showResponse.data.school_cycle_id)
"@
}

Write-Host "   OK"
Write-Host ""

Write-Host "5. Verificando grado y seccion..."

if (
    [string]$showResponse.data.grade_level `
        -ne $gradeLevel
) {
    throw @"
El grado no coincide.

Esperado:
$gradeLevel

Recibido:
$($showResponse.data.grade_level)
"@
}

if (
    [string]$showResponse.data.section `
        -ne $section
) {
    throw @"
La seccion no coincide.

Esperado:
$section

Recibido:
$($showResponse.data.section)
"@
}

Write-Host "   OK"
Write-Host ""

Write-Host "6. Actualizando grupo..."

$updateBodyObject = @{
    capacity = 35

    classroom = "Aula actualizada"

    is_active = $false
}

$updateBody = $updateBodyObject |
    ConvertTo-Json -Depth 10

$updateResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/groups/$schoolGroupId" `
    -Method Patch `
    -WebSession $global:webSession `
    -Headers $headers `
    -ContentType "application/json" `
    -Body $updateBody

if (-not $updateResponse.data) {
    throw "El PATCH no devolvio data."
}

if (
    [string]$updateResponse.data.id `
        -ne $schoolGroupId
) {
    throw "El PATCH devolvio un grupo diferente."
}

if (
    [int]$updateResponse.data.capacity `
        -ne 35
) {
    throw @"
La capacidad no fue actualizada.

Esperado:
35

Recibido:
$($updateResponse.data.capacity)
"@
}

if (
    [string]$updateResponse.data.classroom `
        -ne "Aula actualizada"
) {
    throw @"
El aula no fue actualizada.

Esperado:
Aula actualizada

Recibido:
$($updateResponse.data.classroom)
"@
}

if (
    [bool]$updateResponse.data.is_active `
        -ne $false
) {
    throw @"
El estado activo no fue actualizado.

Esperado:
False

Recibido:
$($updateResponse.data.is_active)
"@
}

Write-Host "   OK"
Write-Host "   PATCH procesado correctamente."
Write-Host ""

Write-Host "7. Verificando persistencia del PATCH..."

$persistedResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/groups/$schoolGroupId" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $headers

if (-not $persistedResponse.data) {
    throw @"
La consulta posterior al PATCH no devolvio data.
"@
}

if (
    [string]$persistedResponse.data.id `
        -ne $schoolGroupId
) {
    throw @"
La consulta posterior devolvio otro grupo.
"@
}

if (
    [int]$persistedResponse.data.capacity `
        -ne 35
) {
    throw @"
La capacidad actualizada no fue persistida.

Esperado:
35

Recibido:
$($persistedResponse.data.capacity)
"@
}

if (
    [string]$persistedResponse.data.classroom `
        -ne "Aula actualizada"
) {
    throw @"
El aula actualizada no fue persistida.

Esperado:
Aula actualizada

Recibido:
$($persistedResponse.data.classroom)
"@
}

if (
    [bool]$persistedResponse.data.is_active `
        -ne $false
) {
    throw @"
El estado actualizado no fue persistido.

Esperado:
False

Recibido:
$($persistedResponse.data.is_active)
"@
}

Write-Host "   OK"
Write-Host "   Capacidad: $($persistedResponse.data.capacity)"
Write-Host "   Aula:      $($persistedResponse.data.classroom)"
Write-Host "   Activo:    $($persistedResponse.data.is_active)"
Write-Host ""

Write-Host "8. Buscando grupo por grado..."

$encodedSearch = [System.Uri]::EscapeDataString(
    $gradeLevel
)

$searchResponse = Invoke-RestMethod `
    -Uri (
        "$backendUrl/api/groups" +
        "?search=$encodedSearch" +
        "&per_page=20"
    ) `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $headers

$searchMatch = $searchResponse.data |
    Where-Object {
        [string]$_.id -eq $schoolGroupId
    } |
    Select-Object -First 1

if (-not $searchMatch) {
    throw @"
El grupo no aparecio en la busqueda.

Grado:
$gradeLevel
"@
}

Write-Host "   OK"
Write-Host ""

Write-Host "9. Verificando filtro is_active=0..."

$inactiveResponse = Invoke-RestMethod `
    -Uri (
        "$backendUrl/api/groups" +
        "?is_active=0" +
        "&per_page=100"
    ) `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $headers

$inactiveMatch = $inactiveResponse.data |
    Where-Object {
        [string]$_.id -eq $schoolGroupId
    } |
    Select-Object -First 1

if (-not $inactiveMatch) {
    throw @"
El grupo no aparecio en:

is_active=0
"@
}

Write-Host "   OK"
Write-Host ""

Write-Host "10. Verificando que no aparezca como activo..."

$activeResponse = Invoke-RestMethod `
    -Uri (
        "$backendUrl/api/groups" +
        "?is_active=1" +
        "&per_page=100"
    ) `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $headers

$activeMatch = $activeResponse.data |
    Where-Object {
        [string]$_.id -eq $schoolGroupId
    } |
    Select-Object -First 1

if ($activeMatch) {
    throw @"
El grupo aparecio incorrectamente en:

is_active=1
"@
}

Write-Host "   OK"
Write-Host ""

Write-Host "11. Verificando acceso sin X-Campus-ID..."

$isolatedSession = New-Object `
    Microsoft.PowerShell.Commands.WebRequestSession

$backendUri = [System.Uri]$backendUrl

$global:webSession.Cookies.GetCookies(
    $backendUri
) |
    ForEach-Object {
        $cookieCopy = New-Object `
            System.Net.Cookie(
                $_.Name,
                $_.Value,
                $_.Path,
                $_.Domain
            )

        $isolatedSession.Cookies.Add(
            $cookieCopy
        )
    }

$isolatedHeaders = @{
    "Accept" = "application/json"
    "Origin" = "http://127.0.0.1:4200"
    "Referer" = "http://127.0.0.1:4200/"
}

Remove-Variable missingCampusResponse `
    -ErrorAction SilentlyContinue

$missingCampusConfirmed = $false

try {
    $missingCampusResponse = Invoke-WebRequest `
        -Uri "$backendUrl/api/groups/$schoolGroupId" `
        -Method Get `
        -WebSession $isolatedSession `
        -Headers $isolatedHeaders `
        -UseBasicParsing

    throw @"
La API permitio consultar el grupo sin X-Campus-ID.

HTTP recibido:
$($missingCampusResponse.StatusCode)

Se esperaba HTTP 422.
"@
}
catch {
    if (
        $_.Exception.Response `
        -and [int]$_.Exception.Response.StatusCode `
            -eq 422
    ) {
        $missingCampusConfirmed = $true
    }
    elseif (
        $_.Exception.Message -like `
            "La API permitio consultar*"
    ) {
        throw
    }
    else {
        throw
    }
}

if (-not $missingCampusConfirmed) {
    throw @"
No fue posible confirmar HTTP 422 sin X-Campus-ID.
"@
}

Write-Host "   OK"
Write-Host "   HTTP 422 confirmado."
Write-Host ""

Write-Host "12. Confirmando que el grupo sigue preservado..."

$finalResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/groups/$schoolGroupId" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $headers

if (
    -not $finalResponse.data `
    -or [string]$finalResponse.data.id `
        -ne $schoolGroupId
) {
    throw @"
El grupo temporal ya no esta disponible.

Test-SchoolGroupsApi.ps1 no debe eliminarlo.
"@
}

Write-Host "   OK"
Write-Host "   Grupo temporal preservado."
Write-Host ""

Write-Host "========================================"
Write-Host " RESULTADO: MODULO SCHOOL GROUPS OK"
Write-Host "========================================"
Write-Host ""
Write-Host "Modulo:                  SCHOOL GROUPS"
Write-Host "Sesion autenticada:      OK"
Write-Host "Carga de estado:         OK"
Write-Host "Consulta:                OK"
Write-Host "Ciclo escolar:           OK"
Write-Host "Grado y seccion:         OK"
Write-Host "Actualizacion PATCH:     OK"
Write-Host "Persistencia update:     OK"
Write-Host "Busqueda:                OK"
Write-Host "Filtro inactivo:         OK"
Write-Host "Filtro activo:           OK"
Write-Host "Campus requerido:        OK"
Write-Host "Registro preservado:     OK"
Write-Host ""
Write-Host "Group ID:"
Write-Host $schoolGroupId
Write-Host ""
Write-Host "El grupo NO fue eliminado."
Write-Host ""
Write-Host (
    "La eliminacion se realizara posteriormente " +
    "con Cleanup-SchoolGroupsTestData.ps1."
)
Write-Host ""