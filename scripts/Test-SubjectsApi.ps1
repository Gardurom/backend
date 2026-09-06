$ErrorActionPreference = "Stop"

$backendUrl = "http://127.0.0.1:8000"

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path

$stateFile = Join-Path `
    $scriptRoot `
    ".test-state\subjects.json"

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

Write-Host ""
Write-Host "========================================"
Write-Host " CONTROL ESCOLAR - PRUEBAS API"
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
    throw @"
No existe el archivo de estado de Subjects:

$stateFile

Ejecuta primero:

.\scripts\Create-SubjectsTestData.ps1
"@
}

Write-Host "1. Cargando estado preparado..."

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

created_by_api_test debe ser true.
"@
}

$campusId = [string]$state.campus.id
$subjectId = [string]$state.subject.id
$subjectCode = [string]$state.subject.code
$subjectName = [string]$state.subject.name
$testSuffix = [string]$state.test.suffix

if (
    [string]::IsNullOrWhiteSpace(
        $campusId
    )
) {
    throw "El estado no contiene Campus ID."
}

if (
    [string]::IsNullOrWhiteSpace(
        $subjectId
    )
) {
    throw "El estado no contiene Subject ID."
}

if (
    [string]::IsNullOrWhiteSpace(
        $subjectCode
    )
) {
    throw "El estado no contiene codigo de materia."
}

if (
    [string]::IsNullOrWhiteSpace(
        $subjectName
    )
) {
    throw "El estado no contiene nombre de materia."
}

if (
    [string]::IsNullOrWhiteSpace(
        $testSuffix
    )
) {
    throw "El estado no contiene sufijo de prueba."
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

Write-Host "3. Consultando materia preparada..."

Remove-Variable showResponse `
    -ErrorAction SilentlyContinue

$showResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/subjects/$subjectId" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $subjectHeaders

if (-not $showResponse.data) {
    throw "La API no devolvio data."
}

if (
    [string]$showResponse.data.id `
        -ne $subjectId
) {
    throw @"
La API devolvio una materia diferente.

Esperado:
$subjectId

Recibido:
$($showResponse.data.id)
"@
}

if (
    [string]$showResponse.data.campus_id `
        -ne $campusId
) {
    throw "La materia pertenece a otro plantel."
}

if (
    [string]$showResponse.data.code `
        -ne $subjectCode
) {
    throw "El codigo de la materia no coincide con el estado."
}

if (
    [string]$showResponse.data.name `
        -ne $subjectName
) {
    throw "El nombre de la materia no coincide con el estado."
}

Write-Host "   OK"
Write-Host ""

Write-Host "4. Verificando plantel..."

if (
    [string]$showResponse.data.campus_id `
        -ne $campusId
) {
    throw "El Campus ID de la materia no coincide."
}

Write-Host "   OK"
Write-Host "   Campus ID: $campusId"
Write-Host ""

Write-Host "5. Verificando codigo..."

if (
    [string]$showResponse.data.code `
        -ne $subjectCode
) {
    throw "El codigo de la materia preparada no coincide."
}

Write-Host "   OK"
Write-Host "   Codigo: $subjectCode"
Write-Host ""

$updatedName = "Materia Actualizada $testSuffix"
$updatedDescription = "Materia temporal actualizada por Test-SubjectsApi.ps1."
$updatedWeeklyHours = [decimal]6.25

$updateBodyObject = @{
    name = $updatedName

    description = $updatedDescription

    weekly_hours = $updatedWeeklyHours

    is_active = $false
}

$updateBody = $updateBodyObject |
    ConvertTo-Json -Depth 10

Write-Host "6. Actualizando materia mediante PATCH..."

Remove-Variable updatedResponse `
    -ErrorAction SilentlyContinue

$updatedResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/subjects/$subjectId" `
    -Method Patch `
    -WebSession $global:webSession `
    -Headers $subjectHeaders `
    -ContentType "application/json" `
    -Body $updateBody

if (-not $updatedResponse.data) {
    throw "La API no devolvio data despues del PATCH."
}

if (
    [string]$updatedResponse.data.id `
        -ne $subjectId
) {
    throw "El PATCH devolvio una materia diferente."
}

if (
    [string]$updatedResponse.data.name `
        -ne $updatedName
) {
    throw @"
El nombre no fue actualizado.

Esperado:
$updatedName

Recibido:
$($updatedResponse.data.name)
"@
}

if (
    [decimal]$updatedResponse.data.weekly_hours `
        -ne $updatedWeeklyHours
) {
    throw @"
Las horas semanales no fueron actualizadas.

Esperado:
$updatedWeeklyHours

Recibido:
$($updatedResponse.data.weekly_hours)
"@
}

if (
    [bool]$updatedResponse.data.is_active `
        -ne $false
) {
    throw "La materia no fue marcada como inactiva."
}

Write-Host "   OK"
Write-Host "   Nombre:         $updatedName"
Write-Host "   Horas semanales: $updatedWeeklyHours"
Write-Host "   Activa:         False"
Write-Host ""

Write-Host "7. Verificando persistencia mediante GET fresco..."

Remove-Variable persistedResponse `
    -ErrorAction SilentlyContinue

$persistedResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/subjects/$subjectId" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $subjectHeaders

if (-not $persistedResponse.data) {
    throw "La API no devolvio la materia despues del PATCH."
}

if (
    [string]$persistedResponse.data.id `
        -ne $subjectId
) {
    throw "El GET posterior devolvio otra materia."
}

if (
    [string]$persistedResponse.data.code `
        -ne $subjectCode
) {
    throw "El codigo original cambio inesperadamente."
}

if (
    [string]$persistedResponse.data.name `
        -ne $updatedName
) {
    throw "El nombre actualizado no persistio."
}

if (
    [string]$persistedResponse.data.description `
        -ne $updatedDescription
) {
    throw "La descripcion actualizada no persistio."
}

if (
    [decimal]$persistedResponse.data.weekly_hours `
        -ne $updatedWeeklyHours
) {
    throw "Las horas semanales actualizadas no persistieron."
}

if (
    [bool]$persistedResponse.data.is_active `
        -ne $false
) {
    throw "El estado inactivo no persistio."
}

Write-Host "   OK"
Write-Host ""

Write-Host "8. Buscando materia por codigo..."

$encodedSearch = [Uri]::EscapeDataString(
    $subjectCode
)

Remove-Variable searchResponse `
    -ErrorAction SilentlyContinue

$searchResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/subjects?search=$encodedSearch&per_page=20" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $subjectHeaders

$searchedSubject = $searchResponse.data |
    Where-Object {
        [string]$_.id -eq $subjectId
    } |
    Select-Object -First 1

if (-not $searchedSubject) {
    throw @"
La materia no aparecio en la busqueda.

Codigo:
$subjectCode
"@
}

Write-Host "   OK"
Write-Host ""

Write-Host "9. Verificando filtro de materias inactivas..."

Remove-Variable inactiveResponse `
    -ErrorAction SilentlyContinue

$inactiveResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/subjects?is_active=0&per_page=100" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $subjectHeaders

$inactiveSubject = $inactiveResponse.data |
    Where-Object {
        [string]$_.id -eq $subjectId
    } |
    Select-Object -First 1

if (-not $inactiveSubject) {
    throw "La materia inactiva no aparecio con is_active=0."
}

Write-Host "   OK"
Write-Host ""

Write-Host "10. Verificando exclusion del filtro activo..."

Remove-Variable activeResponse `
    -ErrorAction SilentlyContinue

$activeResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/subjects?is_active=1&per_page=100" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $subjectHeaders

$unexpectedActiveSubject = $activeResponse.data |
    Where-Object {
        [string]$_.id -eq $subjectId
    } |
    Select-Object -First 1

if ($unexpectedActiveSubject) {
    throw @"
La materia esta inactiva pero aparecio con:

is_active=1
"@
}

Write-Host "   OK"
Write-Host ""

Write-Host "11. Verificando X-Campus-ID obligatorio..."

$isolatedSession = New-Object `
    Microsoft.PowerShell.Commands.WebRequestSession

$baseUri = [Uri]$backendUrl

$sourceCookies = $global:webSession.Cookies.GetCookies(
    $baseUri
)

foreach ($cookie in $sourceCookies) {
    $cookieCopy = New-Object System.Net.Cookie

    $cookieCopy.Name = $cookie.Name
    $cookieCopy.Value = $cookie.Value
    $cookieCopy.Path = $cookie.Path

    if (
        [string]::IsNullOrWhiteSpace(
            [string]$cookie.Domain
        )
    ) {
        $cookieCopy.Domain = $baseUri.Host
    }
    else {
        $cookieCopy.Domain = $cookie.Domain
    }

    $cookieCopy.Secure = $cookie.Secure
    $cookieCopy.HttpOnly = $cookie.HttpOnly

    $isolatedSession.Cookies.Add(
        $cookieCopy
    )
}

$headersWithoutCampus = @{}

foreach (
    $key in $global:authHeaders.Keys
) {
    if (
        [string]$key `
            -ne "X-Campus-ID"
    ) {
        $headersWithoutCampus[$key] = `
            $global:authHeaders[$key]
    }
}

$headersWithoutCampus["Accept"] = `
    "application/json"

$campusRequiredConfirmed = $false
$campusRequiredStatus = $null

try {
    Remove-Variable noCampusResponse `
        -ErrorAction SilentlyContinue

    $noCampusResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/subjects/$subjectId" `
        -Method Get `
        -WebSession $isolatedSession `
        -Headers $headersWithoutCampus

    throw @"
La API permitio consultar una materia
sin X-Campus-ID.
"@
}
catch {
    $campusRequiredStatus = Get-HttpStatusCode `
        -ErrorRecord $_

    if (
        $campusRequiredStatus `
            -eq 422
    ) {
        $campusRequiredConfirmed = $true
    }
    elseif (
        $_.Exception.Message -like `
            "*permitio consultar*"
    ) {
        throw
    }
    else {
        Write-Host (
            Get-ErrorResponseBody `
                -ErrorRecord $_
        ) -ForegroundColor Red

        throw @"
Se esperaba HTTP 422 al omitir X-Campus-ID.

HTTP recibido:
$campusRequiredStatus
"@
    }
}

if (-not $campusRequiredConfirmed) {
    throw "No fue posible confirmar que X-Campus-ID sea obligatorio."
}

Write-Host "   OK"
Write-Host "   HTTP: 422"
Write-Host ""

Write-Host "12. Confirmando que la materia siga preservada..."

Remove-Variable finalResponse `
    -ErrorAction SilentlyContinue

$finalResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/subjects/$subjectId" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $subjectHeaders

if (-not $finalResponse.data) {
    throw "La materia ya no puede consultarse."
}

if (
    [string]$finalResponse.data.id `
        -ne $subjectId
) {
    throw "La consulta final devolvio otra materia."
}

if (
    [string]$finalResponse.data.code `
        -ne $subjectCode
) {
    throw "El codigo final no coincide."
}

if (
    [string]$finalResponse.data.name `
        -ne $updatedName
) {
    throw "El nombre actualizado no se conserva."
}

if (
    [bool]$finalResponse.data.is_active `
        -ne $false
) {
    throw "La materia ya no permanece inactiva."
}

Write-Host "   OK"
Write-Host ""

Write-Host "========================================"
Write-Host " RESULTADO: MODULO SUBJECTS OK" `
    -ForegroundColor Green
Write-Host "========================================"
Write-Host ""
Write-Host "Sesion autenticada:       OK"
Write-Host "Carga de estado:           OK"
Write-Host "Consulta:                  OK"
Write-Host "Plantel:                   OK"
Write-Host "Codigo:                    OK"
Write-Host "Actualizacion PATCH:       OK"
Write-Host "Persistencia update:       OK"
Write-Host "Busqueda:                  OK"
Write-Host "Filtro inactivo:           OK"
Write-Host "Filtro activo:             OK"
Write-Host "Campus requerido:          OK"
Write-Host "Registro preservado:       OK"
Write-Host ""
Write-Host "Subject ID: $subjectId"
Write-Host "Codigo:     $subjectCode"
Write-Host ""
Write-Host "La materia NO fue eliminada."
Write-Host ""
Write-Host "Siguiente fase: Cleanup-SubjectsTestData.ps1"
Write-Host ""