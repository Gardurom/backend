$ErrorActionPreference = "Stop"

$backendUrl = "http://127.0.0.1:8000"

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path

$stateDirectory = Join-Path `
    $scriptRoot `
    ".test-state"

$stateFile = Join-Path `
    $stateDirectory `
    "teachers.json"

Write-Host ""
Write-Host "========================================"
Write-Host " CONTROL ESCOLAR - CREACION DE DATOS API"
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

if (Test-Path -LiteralPath $stateFile) {
    throw @"
Ya existe un archivo de estado para Teachers:

$stateFile

No se creara otro profesor temporal.

Ejecuta primero el cleanup correspondiente
o revisa el estado existente.
"@
}

Write-Host "1. Verificando sesion autenticada..."

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
    throw "La API no devolvio el correo del usuario autenticado."
}

Write-Host "   OK"
Write-Host "   Usuario: $($currentUser.user.email)"
Write-Host ""

Write-Host "2. Obteniendo plantel activo..."

$campusResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/campuses?is_active=1&per_page=20" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $global:authHeaders

$campus = $campusResponse.data |
    Select-Object -First 1

if (-not $campus) {
    throw "No existe ningun plantel activo."
}

$campusId = [string]$campus.id

if ([string]::IsNullOrWhiteSpace($campusId)) {
    throw "El plantel seleccionado no contiene UUID."
}

Write-Host "   OK"
Write-Host "   Plantel: $($campus.name)"
Write-Host "   Campus ID: $campusId"
Write-Host ""

$teacherHeaders = $global:authHeaders.Clone()

$teacherHeaders["Accept"] = "application/json"
$teacherHeaders["X-Campus-ID"] = $campusId

$uniqueSuffix = Get-Date -Format "yyyyMMddHHmmssfff"

$employeeNumber = "TEST-PROF-$uniqueSuffix"

$teacherEmail = (
    "profesor.$uniqueSuffix@example.test"
)

$createBodyObject = @{
    campus_id = $campusId

    employee_number = $employeeNumber

    professional_license = $null

    hired_on = (Get-Date).ToString(
        "yyyy-MM-dd"
    )

    terminated_on = $null

    status = "active"

    person = @{
        first_name = "Profesor"

        middle_name = "Temporal"

        paternal_surname = "Prueba"

        maternal_surname = "API"

        curp = $null

        birth_date = "1985-05-20"

        sex = "unspecified"

        email = $teacherEmail

        phone = "5551000000"

        emergency_phone = "5551000001"

        additional_data = @{
            is_api_test = $true

            test_module = "teachers"

            test_suffix = $uniqueSuffix
        }
    }
}

$createBody = $createBodyObject |
    ConvertTo-Json -Depth 10

$teacherId = $null
$personId = $null

try {
    Write-Host "3. Creando profesor temporal..."

    $createdResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/teachers" `
        -Method Post `
        -WebSession $global:webSession `
        -Headers $teacherHeaders `
        -ContentType "application/json" `
        -Body $createBody

    if (-not $createdResponse.data) {
        throw "La API no devolvio data."
    }

    $teacher = $createdResponse.data

    $teacherId = [string]$teacher.id

    if (
        [string]::IsNullOrWhiteSpace(
            $teacherId
        )
    ) {
        throw @"
La API no devolvio el UUID del profesor.
"@
    }

    if (
        [string]$teacher.campus_id `
            -ne $campusId
    ) {
        throw @"
El profesor fue creado en un plantel diferente.

Esperado:
$campusId

Recibido:
$($teacher.campus_id)
"@
    }

    if (
        [string]$teacher.employee_number `
            -ne $employeeNumber
    ) {
        throw @"
El numero de empleado devuelto no coincide.

Esperado:
$employeeNumber

Recibido:
$($teacher.employee_number)
"@
    }

    if (
        [string]$teacher.person.email `
            -ne $teacherEmail
    ) {
        throw @"
El correo devuelto no coincide.

Esperado:
$teacherEmail

Recibido:
$($teacher.person.email)
"@
    }

    if ($teacher.person.id) {
        $personId = [string]$teacher.person.id
    }

    Write-Host "   OK"
    Write-Host "   Teacher ID: $teacherId"

    if (
        -not [string]::IsNullOrWhiteSpace(
            $personId
        )
    ) {
        Write-Host "   Person ID:  $personId"
    }

    Write-Host "   Numero:     $employeeNumber"
    Write-Host "   Correo:     $teacherEmail"
    Write-Host ""

    Write-Host "4. Confirmando profesor mediante GET..."

    $showResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/teachers/$teacherId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $teacherHeaders

    if (-not $showResponse.data) {
        throw @"
La API no devolvio data al consultar el profesor.
"@
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

    if (
        [string]$showResponse.data.campus_id `
            -ne $campusId
    ) {
        throw @"
El profesor consultado pertenece a otro plantel.
"@
    }

    if (
        [string]$showResponse.data.employee_number `
            -ne $employeeNumber
    ) {
        throw @"
La matricula laboral consultada no coincide.
"@
    }

    if (
        [string]$showResponse.data.person.email `
            -ne $teacherEmail
    ) {
        throw @"
El correo consultado no coincide.
"@
    }

    if (
        [string]::IsNullOrWhiteSpace(
            $personId
        ) `
        -and $showResponse.data.person.id
    ) {
        $personId = [string] `
            $showResponse.data.person.id
    }

    Write-Host "   OK"
    Write-Host ""

    Write-Host "5. Creando archivo de estado..."

    if (
        -not (
            Test-Path -LiteralPath $stateDirectory
        )
    ) {
        New-Item `
            -ItemType Directory `
            -Path $stateDirectory `
            -Force |
            Out-Null
    }

    $stateObject = [ordered]@{
        module = "teachers"

        created_at = (
            Get-Date
        ).ToString("o")

        backend_url = $backendUrl

        campus = [ordered]@{
            id = $campusId
            name = [string]$campus.name
        }

        teacher = [ordered]@{
            id = $teacherId

            person_id = $personId

            employee_number = $employeeNumber

            email = $teacherEmail
        }

        test = [ordered]@{
            suffix = $uniqueSuffix

            created_by_api_test = $true
        }
    }

    $stateJson = $stateObject |
        ConvertTo-Json -Depth 10

    $utf8NoBom = New-Object `
        System.Text.UTF8Encoding($false)

    [System.IO.File]::WriteAllText(
        $stateFile,
        $stateJson,
        $utf8NoBom
    )

    if (
        -not (
            Test-Path -LiteralPath $stateFile
        )
    ) {
        throw @"
No fue posible crear:

$stateFile
"@
    }

    Write-Host "   OK"
    Write-Host "   Estado: $stateFile"
    Write-Host ""

    Write-Host "6. Verificando archivo de estado..."

    $savedStateJson = [System.IO.File]::ReadAllText(
        $stateFile
    )

    $savedState = $savedStateJson |
        ConvertFrom-Json

    if (
        [string]$savedState.teacher.id `
            -ne $teacherId
    ) {
        throw @"
El UUID guardado en teachers.json no coincide.
"@
    }

    if (
        [string]$savedState.teacher.employee_number `
            -ne $employeeNumber
    ) {
        throw @"
El numero de empleado guardado no coincide.
"@
    }

    if (
        $savedState.test.created_by_api_test `
            -ne $true
    ) {
        throw @"
El archivo de estado no contiene el marcador:
created_by_api_test = true
"@
    }

    Write-Host "   OK"
    Write-Host ""
}
catch {
    Write-Host ""
    Write-Host `
        "FALLO LA CREACION DE DATOS DE TEACHERS." `
        -ForegroundColor Red

    Write-Host $_.Exception.Message `
        -ForegroundColor Red

    if ($teacherId) {
        Write-Host ""
        Write-Host `
            "Intentando eliminar el profesor incompleto..." `
            -ForegroundColor Yellow

        try {
            Invoke-RestMethod `
                -Uri "$backendUrl/api/teachers/$teacherId" `
                -Method Delete `
                -WebSession $global:webSession `
                -Headers $teacherHeaders |
                Out-Null

            Write-Host `
                "Profesor temporal eliminado." `
                -ForegroundColor Yellow
        }
        catch {
            Write-Host `
                "No fue posible eliminar automaticamente el profesor." `
                -ForegroundColor Red

            Write-Host `
                "Teacher ID: $teacherId" `
                -ForegroundColor Red
        }
    }

    if (Test-Path -LiteralPath $stateFile) {
        Remove-Item `
            -LiteralPath $stateFile `
            -Force
    }

    throw
}

Write-Host "========================================"
Write-Host " RESULTADO: CREATE TEACHERS OK"
Write-Host "========================================"
Write-Host ""
Write-Host "Modulo:                  TEACHERS"
Write-Host "Campus ID:               $campusId"
Write-Host "Teacher ID:              $teacherId"
Write-Host "Person ID:               $personId"
Write-Host "Numero de empleado:      $employeeNumber"
Write-Host "Correo:                   $teacherEmail"
Write-Host "Consulta GET:             OK"
Write-Host "Archivo de estado:       OK"
Write-Host ""
Write-Host "El profesor NO fue eliminado."
Write-Host ""
Write-Host (
    "Siguiente fase: Test-TeachersApi.ps1"
)
Write-Host ""