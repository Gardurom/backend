$ErrorActionPreference = "Stop"

$backendUrl = "http://127.0.0.1:8000"

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path

$stateFile = Join-Path `
    $scriptRoot `
    ".test-state\teachers.json"

Write-Host ""
Write-Host "========================================"
Write-Host " CONTROL ESCOLAR - PRUEBA API"
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
    throw @"
No existe el archivo de estado de Teachers:

$stateFile

Ejecuta primero:

.\scripts\Create-TeachersTestData.ps1
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

if ($state.module -ne "teachers") {
    throw @"
El archivo de estado no pertenece al modulo Teachers.

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
$teacherId = [string]$state.teacher.id
$personId = [string]$state.teacher.person_id
$employeeNumber = [string] `
    $state.teacher.employee_number
$teacherEmail = [string]$state.teacher.email

if ([string]::IsNullOrWhiteSpace($campusId)) {
    throw "El estado no contiene campus.id."
}

if ([string]::IsNullOrWhiteSpace($teacherId)) {
    throw "El estado no contiene teacher.id."
}

if (
    [string]::IsNullOrWhiteSpace(
        $employeeNumber
    )
) {
    throw @"
El estado no contiene:
teacher.employee_number
"@
}

if (
    [string]::IsNullOrWhiteSpace(
        $teacherEmail
    )
) {
    throw "El estado no contiene teacher.email."
}

$headers = $global:authHeaders.Clone()

$headers["Accept"] = "application/json"
$headers["X-Campus-ID"] = $campusId

Write-Host "   OK"
Write-Host "   Campus ID:  $campusId"
Write-Host "   Teacher ID: $teacherId"
Write-Host "   Numero:     $employeeNumber"
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

Write-Host "3. Consultando profesor preparado..."

$showResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/teachers/$teacherId" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $headers

if (-not $showResponse.data) {
    throw "La API no devolvio data."
}

if (
    [string]$showResponse.data.id `
        -ne $teacherId
) {
    throw @"
La API devolvio un profesor diferente.

Esperado:
$teacherId

Recibido:
$($showResponse.data.id)
"@
}

Write-Host "   OK"

if ($showResponse.data.person.full_name) {
    Write-Host `
        "   Nombre: $($showResponse.data.person.full_name)"
}

Write-Host ""

Write-Host "4. Verificando plantel del profesor..."

if (
    [string]$showResponse.data.campus_id `
        -ne $campusId
) {
    throw @"
El profesor pertenece a otro plantel.

Esperado:
$campusId

Recibido:
$($showResponse.data.campus_id)
"@
}

Write-Host "   OK"
Write-Host ""

Write-Host "5. Verificando numero de empleado..."

if (
    [string]$showResponse.data.employee_number `
        -ne $employeeNumber
) {
    throw @"
El numero de empleado no coincide.

Esperado:
$employeeNumber

Recibido:
$($showResponse.data.employee_number)
"@
}

Write-Host "   OK"
Write-Host "   Numero: $employeeNumber"
Write-Host ""

Write-Host "6. Verificando correo de la persona..."

if (-not $showResponse.data.person) {
    throw "La respuesta no contiene person."
}

if (
    [string]$showResponse.data.person.email `
        -ne $teacherEmail
) {
    throw @"
El correo no coincide.

Esperado:
$teacherEmail

Recibido:
$($showResponse.data.person.email)
"@
}

if (
    -not [string]::IsNullOrWhiteSpace(
        $personId
    ) `
    -and [string]$showResponse.data.person.id `
        -ne $personId
) {
    throw @"
El Person ID no coincide.

Esperado:
$personId

Recibido:
$($showResponse.data.person.id)
"@
}

Write-Host "   OK"
Write-Host "   Correo: $teacherEmail"
Write-Host ""

Write-Host "7. Actualizando profesor..."

$professionalLicense = (
    "CED-TEST-" +
    $state.test.suffix
)

$updateBodyObject = @{
    professional_license = $professionalLicense

    status = "leave"

    person = @{
        first_name = "Profesor Actualizado"

        phone = "5551000099"
    }
}

$updateBody = $updateBodyObject |
    ConvertTo-Json -Depth 10

$updateResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/teachers/$teacherId" `
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
        -ne $teacherId
) {
    throw @"
El PATCH devolvio un profesor diferente.
"@
}

if (
    [string]$updateResponse.data.status `
        -ne "leave"
) {
    throw @"
El estado no fue actualizado.

Esperado:
leave

Recibido:
$($updateResponse.data.status)
"@
}

if (
    [string]$updateResponse.data.professional_license `
        -ne $professionalLicense
) {
    throw @"
La cedula profesional no fue actualizada.

Esperado:
$professionalLicense

Recibido:
$($updateResponse.data.professional_license)
"@
}

if (
    [string]$updateResponse.data.person.first_name `
        -ne "Profesor Actualizado"
) {
    throw @"
El nombre no fue actualizado.

Esperado:
Profesor Actualizado

Recibido:
$($updateResponse.data.person.first_name)
"@
}

Write-Host "   OK"
Write-Host "   PATCH procesado correctamente."
Write-Host ""

Write-Host "8. Verificando persistencia del PATCH..."

$persistedResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/teachers/$teacherId" `
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
        -ne $teacherId
) {
    throw @"
La consulta posterior devolvio otro profesor.
"@
}

if (
    [string]$persistedResponse.data.status `
        -ne "leave"
) {
    throw @"
El estado actualizado no fue persistido.

Esperado:
leave

Recibido:
$($persistedResponse.data.status)
"@
}

if (
    [string]$persistedResponse.data.professional_license `
        -ne $professionalLicense
) {
    throw @"
La cedula profesional no fue persistida.

Esperado:
$professionalLicense

Recibido:
$($persistedResponse.data.professional_license)
"@
}

if (
    [string]$persistedResponse.data.person.first_name `
        -ne "Profesor Actualizado"
) {
    throw @"
El nombre actualizado no fue persistido.

Esperado:
Profesor Actualizado

Recibido:
$($persistedResponse.data.person.first_name)
"@
}

if (
    [string]$persistedResponse.data.person.phone `
        -ne "5551000099"
) {
    throw @"
El telefono actualizado no fue persistido.

Esperado:
5551000099

Recibido:
$($persistedResponse.data.person.phone)
"@
}

Write-Host "   OK"
Write-Host "   Estado:   $($persistedResponse.data.status)"
Write-Host (
    "   Cedula:   " +
    $persistedResponse.data.professional_license
)
Write-Host (
    "   Nombre:   " +
    $persistedResponse.data.person.first_name
)
Write-Host (
    "   Telefono: " +
    $persistedResponse.data.person.phone
)
Write-Host ""

Write-Host "9. Buscando profesor por numero..."

$encodedEmployeeNumber = [System.Uri]::EscapeDataString(
    $employeeNumber
)

$searchResponse = Invoke-RestMethod `
    -Uri (
        "$backendUrl/api/teachers" +
        "?search=$encodedEmployeeNumber" +
        "&per_page=20"
    ) `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $headers

$searchMatch = $searchResponse.data |
    Where-Object {
        [string]$_.id -eq $teacherId
    } |
    Select-Object -First 1

if (-not $searchMatch) {
    throw @"
El profesor no aparecio al buscarlo por numero.

Numero:
$employeeNumber
"@
}

Write-Host "   OK"
Write-Host ""

Write-Host "10. Verificando filtro status=leave..."

$leaveResponse = Invoke-RestMethod `
    -Uri (
        "$backendUrl/api/teachers" +
        "?status=leave" +
        "&per_page=100"
    ) `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $headers

$leaveMatch = $leaveResponse.data |
    Where-Object {
        [string]$_.id -eq $teacherId
    } |
    Select-Object -First 1

if (-not $leaveMatch) {
    throw @"
El profesor no aparecio en:

status=leave
"@
}

Write-Host "   OK"
Write-Host ""

Write-Host "11. Verificando que no aparezca como active..."

$activeResponse = Invoke-RestMethod `
    -Uri (
        "$backendUrl/api/teachers" +
        "?status=active" +
        "&per_page=100"
    ) `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $headers

$activeMatch = $activeResponse.data |
    Where-Object {
        [string]$_.id -eq $teacherId
    } |
    Select-Object -First 1

if ($activeMatch) {
    throw @"
El profesor aparecio incorrectamente en:

status=active
"@
}

Write-Host "   OK"
Write-Host ""

Write-Host "12. Verificando acceso sin X-Campus-ID..."

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
        -Uri "$backendUrl/api/teachers/$teacherId" `
        -Method Get `
        -WebSession $isolatedSession `
        -Headers $isolatedHeaders `
        -UseBasicParsing

    throw @"
La API permitio consultar el profesor sin X-Campus-ID.

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

Write-Host "13. Confirmando que el profesor sigue preservado..."

$finalResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/teachers/$teacherId" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $headers

if (
    -not $finalResponse.data `
    -or [string]$finalResponse.data.id `
        -ne $teacherId
) {
    throw @"
El profesor temporal ya no esta disponible.

Test-TeachersApi.ps1 no debe eliminarlo.
"@
}

Write-Host "   OK"
Write-Host "   Profesor temporal preservado."
Write-Host ""

Write-Host "========================================"
Write-Host " RESULTADO: MODULO TEACHERS OK"
Write-Host "========================================"
Write-Host ""
Write-Host "Modulo:                  TEACHERS"
Write-Host "Sesion autenticada:      OK"
Write-Host "Carga de estado:         OK"
Write-Host "Consulta:                OK"
Write-Host "Plantel:                 OK"
Write-Host "Numero de empleado:      OK"
Write-Host "Correo:                   OK"
Write-Host "Actualizacion PATCH:     OK"
Write-Host "Persistencia update:     OK"
Write-Host "Telefono persistido:     OK"
Write-Host "Busqueda:                OK"
Write-Host "Filtro leave:            OK"
Write-Host "Filtro active:           OK"
Write-Host "Campus requerido:        OK"
Write-Host "Registro preservado:     OK"
Write-Host ""
Write-Host "Teacher ID:"
Write-Host $teacherId
Write-Host ""
Write-Host "El profesor NO fue eliminado."
Write-Host ""
Write-Host (
    "La eliminacion se realizara posteriormente " +
    "con Cleanup-TeachersTestData.ps1."
)
Write-Host ""