$ErrorActionPreference = "Stop"

$backendUrl = "http://127.0.0.1:8000"

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path

$stateFile = Join-Path `
    $scriptRoot `
    ".test-state\students.json"

Write-Host ""
Write-Host "========================================"
Write-Host " CONTROL ESCOLAR - PRUEBA API"
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
    throw @"
No existe el archivo de estado de Students:

$stateFile

Ejecuta primero:

.\scripts\Create-StudentsTestData.ps1
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
No fue posible interpretar el archivo:

$stateFile

El JSON del estado no es valido.
"@
}

if ($state.module -ne "students") {
    throw @"
El archivo de estado no pertenece al modulo Students.

Modulo encontrado:
$($state.module)
"@
}

if (
    -not $state.test `
    -or $state.test.created_by_api_test -ne $true
) {
    throw @"
El archivo de estado no contiene el marcador de seguridad esperado:

test.created_by_api_test = true

Prueba cancelada.
"@
}

$campusId = [string]$state.campus.id
$studentId = [string]$state.student.id
$enrollmentNumber = [string] `
    $state.student.enrollment_number
$studentEmail = [string]$state.student.email

if ([string]::IsNullOrWhiteSpace($campusId)) {
    throw "El archivo de estado no contiene campus.id."
}

if ([string]::IsNullOrWhiteSpace($studentId)) {
    throw "El archivo de estado no contiene student.id."
}

if (
    [string]::IsNullOrWhiteSpace(
        $enrollmentNumber
    )
) {
    throw @"
El archivo de estado no contiene:
student.enrollment_number
"@
}

if (
    [string]::IsNullOrWhiteSpace(
        $studentEmail
    )
) {
    throw "El archivo de estado no contiene student.email."
}

$headers = $global:authHeaders.Clone()

$headers["Accept"] = "application/json"
$headers["X-Campus-ID"] = $campusId

Write-Host "   OK"
Write-Host "   Campus ID:  $campusId"
Write-Host "   Student ID: $studentId"
Write-Host "   Matricula:  $enrollmentNumber"
Write-Host ""

Write-Host "2. Verificando sesion autenticada..."

$currentUser = Invoke-RestMethod `
    -Uri "$backendUrl/api/auth/me" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $headers

if (-not $currentUser.user) {
    throw @"
La API no devolvio el usuario autenticado.
"@
}

if (
    [string]::IsNullOrWhiteSpace(
        [string]$currentUser.user.email
    )
) {
    throw @"
La API no devolvio el correo del usuario autenticado.
"@
}

Write-Host "   OK"
Write-Host "   Usuario: $($currentUser.user.email)"
Write-Host ""

Write-Host "3. Consultando alumno preparado..."

$showResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/students/$studentId" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $headers

if (-not $showResponse.data) {
    throw "La API no devolvio data para el alumno."
}

if (
    [string]$showResponse.data.id `
        -ne $studentId
) {
    throw @"
La API devolvio un alumno diferente.

Esperado:
$studentId

Recibido:
$($showResponse.data.id)
"@
}

Write-Host "   OK"
Write-Host "   Alumno encontrado."

if ($showResponse.data.person.full_name) {
    Write-Host `
        "   Nombre: $($showResponse.data.person.full_name)"
}

Write-Host ""

Write-Host "4. Verificando plantel del alumno..."

if (
    [string]$showResponse.data.campus_id `
        -ne $campusId
) {
    throw @"
El alumno pertenece a un plantel diferente.

Esperado:
$campusId

Recibido:
$($showResponse.data.campus_id)
"@
}

Write-Host "   OK"
Write-Host ""

Write-Host "5. Verificando matricula..."

if (
    [string]$showResponse.data.enrollment_number `
        -ne $enrollmentNumber
) {
    throw @"
La matricula no coincide con el estado.

Esperado:
$enrollmentNumber

Recibido:
$($showResponse.data.enrollment_number)
"@
}

Write-Host "   OK"
Write-Host "   Matricula: $enrollmentNumber"
Write-Host ""

Write-Host "6. Verificando correo de la persona..."

if (-not $showResponse.data.person) {
    throw "La respuesta no contiene person."
}

if (
    [string]$showResponse.data.person.email `
        -ne $studentEmail
) {
    throw @"
El correo no coincide con el estado.

Esperado:
$studentEmail

Recibido:
$($showResponse.data.person.email)
"@
}

Write-Host "   OK"
Write-Host "   Correo: $studentEmail"
Write-Host ""

Write-Host "7. Actualizando alumno..."

$updateBody = @{
    status = "inactive"

    notes = (
        "Registro temporal actualizado por " +
        "Test-StudentsApi.ps1."
    )

    person = @{
        first_name = "Alumno Actualizado"
        phone = "5550000099"
    }
} |
    ConvertTo-Json -Depth 10

$updateResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/students/$studentId" `
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
        -ne $studentId
) {
    throw @"
El PATCH devolvio un alumno diferente.

Esperado:
$studentId

Recibido:
$($updateResponse.data.id)
"@
}

if (
    [string]$updateResponse.data.status `
        -ne "inactive"
) {
    throw @"
El estado no fue actualizado.

Esperado:
inactive

Recibido:
$($updateResponse.data.status)
"@
}

if (
    [string]$updateResponse.data.person.first_name `
        -ne "Alumno Actualizado"
) {
    throw @"
El nombre no fue actualizado.

Esperado:
Alumno Actualizado

Recibido:
$($updateResponse.data.person.first_name)
"@
}

Write-Host "   OK"
Write-Host "   PATCH procesado correctamente."
Write-Host ""

Write-Host "8. Verificando persistencia del PATCH..."

$persistedResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/students/$studentId" `
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
        -ne $studentId
) {
    throw @"
La consulta posterior devolvio otro alumno.
"@
}

if (
    [string]$persistedResponse.data.status `
        -ne "inactive"
) {
    throw @"
El estado actualizado no fue persistido.

Esperado:
inactive

Recibido:
$($persistedResponse.data.status)
"@
}

if (
    [string]$persistedResponse.data.person.first_name `
        -ne "Alumno Actualizado"
) {
    throw @"
El nombre actualizado no fue persistido.

Esperado:
Alumno Actualizado

Recibido:
$($persistedResponse.data.person.first_name)
"@
}

if (
    [string]$persistedResponse.data.person.phone `
        -ne "5550000099"
) {
    throw @"
El telefono actualizado no fue persistido.

Esperado:
5550000099

Recibido:
$($persistedResponse.data.person.phone)
"@
}

Write-Host "   OK"
Write-Host `
    "   Estado:   $($persistedResponse.data.status)"
Write-Host `
    "   Nombre:   $($persistedResponse.data.person.first_name)"
Write-Host `
    "   Telefono: $($persistedResponse.data.person.phone)"
Write-Host ""

Write-Host "9. Buscando alumno mediante matricula..."

$encodedEnrollment = [System.Uri]::EscapeDataString(
    $enrollmentNumber
)

$searchResponse = Invoke-RestMethod `
    -Uri (
        "$backendUrl/api/students" +
        "?search=$encodedEnrollment" +
        "&per_page=20"
    ) `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $headers

$searchMatch = $searchResponse.data |
    Where-Object {
        [string]$_.id -eq $studentId
    } |
    Select-Object -First 1

if (-not $searchMatch) {
    throw @"
El alumno no aparecio al buscarlo por matricula.

Matricula:
$enrollmentNumber
"@
}

Write-Host "   OK"
Write-Host ""

Write-Host "10. Verificando filtro por estado inactive..."

$inactiveResponse = Invoke-RestMethod `
    -Uri (
        "$backendUrl/api/students" +
        "?status=inactive" +
        "&per_page=100"
    ) `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $headers

$inactiveMatch = $inactiveResponse.data |
    Where-Object {
        [string]$_.id -eq $studentId
    } |
    Select-Object -First 1

if (-not $inactiveMatch) {
    throw @"
El alumno no aparecio en el filtro:
status=inactive
"@
}

Write-Host "   OK"
Write-Host ""

Write-Host "11. Verificando que no aparezca como active..."

$activeResponse = Invoke-RestMethod `
    -Uri (
        "$backendUrl/api/students" +
        "?status=active" +
        "&per_page=100"
    ) `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $headers

$activeMatch = $activeResponse.data |
    Where-Object {
        [string]$_.id -eq $studentId
    } |
    Select-Object -First 1

if ($activeMatch) {
    throw @"
El alumno aparecio incorrectamente en:
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
        -Uri "$backendUrl/api/students/$studentId" `
        -Method Get `
        -WebSession $isolatedSession `
        -Headers $isolatedHeaders `
        -UseBasicParsing

    throw @"
La API permitio consultar el alumno sin X-Campus-ID.

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

Write-Host "13. Confirmando que el registro sigue preservado..."

$finalResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/students/$studentId" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $headers

if (
    -not $finalResponse.data `
    -or [string]$finalResponse.data.id `
        -ne $studentId
) {
    throw @"
El alumno temporal ya no esta disponible.

El script Test-StudentsApi.ps1 no debe eliminarlo.
"@
}

Write-Host "   OK"
Write-Host "   Alumno temporal preservado."
Write-Host ""

Write-Host "========================================"
Write-Host " RESULTADO: MODULO STUDENTS OK"
Write-Host "========================================"
Write-Host ""
Write-Host "Modulo:                  STUDENTS"
Write-Host "Sesion autenticada:      OK"
Write-Host "Carga de estado:         OK"
Write-Host "Consulta:                OK"
Write-Host "Plantel:                 OK"
Write-Host "Matricula:               OK"
Write-Host "Correo:                   OK"
Write-Host "Actualizacion PATCH:     OK"
Write-Host "Persistencia update:     OK"
Write-Host "Telefono persistido:     OK"
Write-Host "Busqueda:                OK"
Write-Host "Filtro inactive:         OK"
Write-Host "Filtro active:           OK"
Write-Host "Campus requerido:        OK"
Write-Host "Registro preservado:     OK"
Write-Host ""
Write-Host "Student ID:"
Write-Host $studentId
Write-Host ""
Write-Host "El alumno NO fue eliminado."
Write-Host ""
Write-Host (
    "La eliminacion se realizara posteriormente " +
    "con Cleanup-StudentsTestData.ps1."
)
Write-Host ""