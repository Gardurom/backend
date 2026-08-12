$ErrorActionPreference = "Stop"

$backendUrl = "http://127.0.0.1:8000"

if (-not $global:webSession) {
    throw "No existe una sesion HTTP. Ejecuta primero: . .\scripts\Test-Auth.ps1"
}

if (-not $global:authHeaders) {
    throw "No existen los encabezados de autenticacion. Ejecuta primero Test-Auth.ps1."
}

Write-Host "1. Comprobando sesion autenticada..."

$currentUser = Invoke-RestMethod `
    -Uri "$backendUrl/api/auth/me" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $global:authHeaders

Write-Host "   Usuario autenticado: $($currentUser.user.email)"

Write-Host "2. Obteniendo planteles..."

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

$campusId = $campus.id

Write-Host "   Plantel seleccionado: $($campus.name)"
Write-Host "   Campus ID: $campusId"

$studentHeaders = $global:authHeaders.Clone()
$studentHeaders["X-Campus-ID"] = $campusId

$uniqueSuffix = Get-Date -Format "yyyyMMddHHmmss"

$createBody = @{
    campus_id = $campusId
    enrollment_number = "TEST-$uniqueSuffix"
    enrolled_on = (Get-Date).ToString("yyyy-MM-dd")
    status = "active"
    notes = "Registro temporal creado por la prueba API."
    person = @{
        first_name = "Alumno"
        middle_name = "Temporal"
        paternal_surname = "Prueba"
        maternal_surname = "API"
        curp = $null
        birth_date = "2012-06-15"
        sex = "unspecified"
        email = "alumno.$uniqueSuffix@example.test"
        phone = "5550000000"
        emergency_phone = "5550000001"
        additional_data = @{
            is_api_test = $true
        }
    }
} | ConvertTo-Json -Depth 10

$studentId = $null
$testCompleted = $false

try {
    Write-Host "3. Creando alumno..."

    $createdResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/students" `
        -Method Post `
        -WebSession $global:webSession `
        -Headers $studentHeaders `
        -ContentType "application/json" `
        -Body $createBody

    $student = $createdResponse.data
    $studentId = $student.id

    if (-not $studentId) {
        throw "La API no devolvio el UUID del alumno."
    }

    Write-Host "   Alumno creado: $studentId"
    Write-Host "   Matricula: $($student.enrollment_number)"

    Write-Host "4. Verificando que la sesion siga activa..."

    $currentUser = Invoke-RestMethod `
        -Uri "$backendUrl/api/auth/me" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $global:authHeaders

    Write-Host "   Sesion activa: $($currentUser.user.email)"

    Write-Host "5. Consultando alumno..."

    $showResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/students/$studentId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $studentHeaders

    if ($showResponse.data.id -ne $studentId) {
        throw "La consulta devolvio un alumno diferente."
    }

    Write-Host "   Alumno consultado correctamente."
    Write-Host "   Nombre: $($showResponse.data.person.full_name)"

    $updateBody = @{
        status = "inactive"
        notes = "Registro temporal actualizado correctamente."
        person = @{
            first_name = "Alumno Actualizado"
            phone = "5550000099"
        }
    } | ConvertTo-Json -Depth 10

    Write-Host "6. Actualizando alumno..."

    $updatedResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/students/$studentId" `
        -Method Patch `
        -WebSession $global:webSession `
        -Headers $studentHeaders `
        -ContentType "application/json" `
        -Body $updateBody

    if ($updatedResponse.data.status -ne "inactive") {
        throw "El estado del alumno no fue actualizado."
    }

    if (
        $updatedResponse.data.person.first_name `
            -ne "Alumno Actualizado"
    ) {
        throw "El nombre del alumno no fue actualizado."
    }

    Write-Host "   Alumno actualizado correctamente."

    Write-Host "7. Buscando alumno en el listado..."

    $searchValue = [Uri]::EscapeDataString(
        "TEST-$uniqueSuffix"
    )

    $listResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/students?search=$searchValue&per_page=10" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $studentHeaders

    $listedStudent = $listResponse.data |
        Where-Object {
            $_.id -eq $studentId
        } |
        Select-Object -First 1

    if (-not $listedStudent) {
        throw "El alumno no aparecio en el listado filtrado."
    }

    Write-Host "   Alumno encontrado mediante busqueda."

    Write-Host "8. Eliminando alumno temporal..."

    Invoke-RestMethod `
        -Uri "$backendUrl/api/students/$studentId" `
        -Method Delete `
        -WebSession $global:webSession `
        -Headers $studentHeaders |
        Out-Null

    Write-Host "   Alumno eliminado."

    Write-Host "9. Confirmando eliminacion..."

    $notFoundConfirmed = $false

    try {
        Invoke-RestMethod `
            -Uri "$backendUrl/api/students/$studentId" `
            -Method Get `
            -WebSession $global:webSession `
            -Headers $studentHeaders |
            Out-Null
    }
    catch {
        if (
            $_.Exception.Response `
            -and [int]$_.Exception.Response.StatusCode -eq 404
        ) {
            $notFoundConfirmed = $true
        }
        else {
            throw
        }
    }

    if (-not $notFoundConfirmed) {
        throw "El alumno eliminado todavia puede consultarse."
    }

    $studentId = $null
    $testCompleted = $true
}
finally {
    
    if ($studentId) {
        Write-Host ""
        Write-Host "Intentando limpiar el alumno temporal..." `
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
            Write-Host "No fue posible eliminar automaticamente el alumno $studentId." `
                -ForegroundColor Red
        }
    }
}

if ($testCompleted) {
    Write-Host ""
    Write-Host "PRUEBA COMPLETADA CORRECTAMENTE" `
        -ForegroundColor Green
    Write-Host "Crear:      OK"
    Write-Host "Sesion:     OK"
    Write-Host "Consultar:  OK"
    Write-Host "Actualizar: OK"
    Write-Host "Buscar:     OK"
    Write-Host "Eliminar:   OK"
}
