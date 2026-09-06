$ErrorActionPreference = "Stop"

$backendUrl = "http://127.0.0.1:8000"

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path

$stateDirectory = Join-Path `
    $scriptRoot `
    ".test-state"

$stateFile = Join-Path `
    $stateDirectory `
    "students.json"

Write-Host ""
Write-Host "========================================"
Write-Host " CONTROL ESCOLAR - DATOS DE PRUEBA"
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
No existen los encabezados de autenticacion.

Ejecuta primero:

. .\scripts\Test-Auth.ps1
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

Write-Host "   OK"
Write-Host "   Usuario: $($currentUser.user.email)"
Write-Host ""

Write-Host "2. Obteniendo un plantel activo..."

$campusResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/campuses?is_active=1&per_page=20" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $global:authHeaders

$campus = $campusResponse.data |
    Select-Object -First 1

if (-not $campus) {
    throw "No existe ningun plantel activo disponible para la prueba."
}

$campusId = $campus.id

if (-not $campusId) {
    throw "El plantel seleccionado no tiene un UUID valido."
}

Write-Host "   OK"
Write-Host "   Plantel: $($campus.name)"
Write-Host "   Campus ID: $campusId"
Write-Host ""

$studentHeaders = $global:authHeaders.Clone()
$studentHeaders["X-Campus-ID"] = $campusId

$uniqueSuffix = Get-Date -Format "yyyyMMddHHmmssfff"

$enrollmentNumber = "TEST-STU-$uniqueSuffix"

$testEmail = "student.$uniqueSuffix@example.test"

$createBodyObject = @{
    campus_id = $campusId
    enrollment_number = $enrollmentNumber
    enrolled_on = (Get-Date).ToString("yyyy-MM-dd")
    status = "active"
    notes = "Registro temporal para pruebas automatizadas del API de alumnos."
    person = @{
        first_name = "Alumno"
        middle_name = "Temporal"
        paternal_surname = "Prueba"
        maternal_surname = "API"
        curp = $null
        birth_date = "2012-06-15"
        sex = "unspecified"
        email = $testEmail
        phone = "5550000000"
        emergency_phone = "5550000001"
        additional_data = @{
            is_api_test = $true
            test_module = "students"
            test_suffix = $uniqueSuffix
        }
    }
}

$createBody = $createBodyObject |
    ConvertTo-Json -Depth 10

$studentId = $null
$creationSucceeded = $false

try {
    Write-Host "3. Creando alumno de prueba..."

    $createdResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/students" `
        -Method Post `
        -WebSession $global:webSession `
        -Headers $studentHeaders `
        -ContentType "application/json; charset=utf-8" `
        -Body $createBody

    $student = $createdResponse.data

    if (-not $student) {
        throw "La API no devolvio los datos del alumno creado."
    }

    $studentId = $student.id

    if (-not $studentId) {
        throw "La API no devolvio el UUID del alumno creado."
    }

    if (
        $student.enrollment_number `
            -ne $enrollmentNumber
    ) {
        throw @"
La matricula devuelta por la API no coincide.

Esperada:
$enrollmentNumber

Recibida:
$($student.enrollment_number)
"@
    }

    Write-Host "   OK"
    Write-Host "   Student ID: $studentId"
    Write-Host "   Matricula: $($student.enrollment_number)"
    Write-Host ""

    Write-Host "4. Confirmando que el alumno pueda consultarse..."

    $showResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/students/$studentId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $studentHeaders

    if (-not $showResponse.data) {
        throw "La API no devolvio el alumno creado."
    }

    if (
        $showResponse.data.id `
            -ne $studentId
    ) {
        throw "La API devolvio un alumno diferente."
    }

    Write-Host "   OK"
    Write-Host ""

    Write-Host "5. Preparando archivo de estado..."

    if (-not (Test-Path $stateDirectory)) {
        New-Item `
            -Path $stateDirectory `
            -ItemType Directory `
            -Force |
            Out-Null
    }

    $state = [ordered]@{
        module = "students"
        created_at = (Get-Date).ToString("o")
        backend_url = $backendUrl
        campus = [ordered]@{
            id = $campusId
            name = $campus.name
        }
        student = [ordered]@{
            id = $studentId
            enrollment_number = $enrollmentNumber
            email = $testEmail
        }
        test = [ordered]@{
            suffix = $uniqueSuffix
            created_by_api_test = $true
        }
    }

    $stateJson = $state |
        ConvertTo-Json -Depth 10

    $utf8NoBom = New-Object `
        System.Text.UTF8Encoding($false)

    [System.IO.File]::WriteAllText(
        $stateFile,
        $stateJson,
        $utf8NoBom
    )

    if (-not (Test-Path $stateFile)) {
        throw "No fue posible crear el archivo de estado."
    }

    Write-Host "   OK"
    Write-Host "   Estado: $stateFile"
    Write-Host ""

    $creationSucceeded = $true
}
finally {

    if (
        (-not $creationSucceeded) `
        -and $studentId
    ) {
        Write-Host ""
        Write-Host "La preparacion fallo." `
            -ForegroundColor Yellow

        Write-Host "Intentando eliminar el alumno temporal..." `
            -ForegroundColor Yellow

        try {
            Invoke-RestMethod `
                -Uri "$backendUrl/api/students/$studentId" `
                -Method Delete `
                -WebSession $global:webSession `
                -Headers $studentHeaders |
                Out-Null

            Write-Host "Alumno temporal eliminado." `
                -ForegroundColor Yellow
        }
        catch {
            Write-Host `
                "ADVERTENCIA: no fue posible eliminar automaticamente el alumno $studentId." `
                -ForegroundColor Red
        }
    }
}

if ($creationSucceeded) {
    Write-Host "========================================"
    Write-Host " DATOS DE PRUEBA CREADOS CORRECTAMENTE" `
        -ForegroundColor Green
    Write-Host "========================================"
    Write-Host ""
    Write-Host "Modulo:       STUDENTS"
    Write-Host "Campus ID:    $campusId"
    Write-Host "Student ID:   $studentId"
    Write-Host "Matricula:    $enrollmentNumber"
    Write-Host ""
    Write-Host "Archivo de estado:"
    Write-Host $stateFile
    Write-Host ""
    Write-Host "NO elimines manualmente este alumno."
    Write-Host "El script de limpieza utilizara este archivo."
}