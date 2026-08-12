$ErrorActionPreference = "Stop"

$backendUrl = "http://127.0.0.1:8000"

if (-not $global:webSession) {
    throw "No existe una sesion HTTP. Ejecuta primero: . .\scripts\Test-Auth.ps1"
}

if (-not $global:authHeaders) {
    throw "No existen encabezados de autenticacion. Ejecuta primero Test-Auth.ps1."
}

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

Write-Host "1. Comprobando sesion autenticada..."

$currentUser = Invoke-RestMethod `
    -Uri "$backendUrl/api/auth/me" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $global:authHeaders

Write-Host "   Usuario: $($currentUser.user.email)"

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

$campusId = [string]$campus.id

Write-Host "   Plantel: $($campus.name)"
Write-Host "   Campus ID: $campusId"

$teacherHeaders = $global:authHeaders.Clone()
$teacherHeaders["X-Campus-ID"] = $campusId

$uniqueSuffix = Get-Date -Format "yyyyMMddHHmmss"
$employeeNumber = "TEST-PROF-$uniqueSuffix"

$createBody = @{
    campus_id = $campusId
    employee_number = $employeeNumber
    professional_license = $null
    hired_on = (Get-Date).ToString("yyyy-MM-dd")
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
        email = "profesor.$uniqueSuffix@example.test"
        phone = "5551000000"
        emergency_phone = "5551000001"
        additional_data = @{
            is_api_test = $true
        }
    }
} | ConvertTo-Json -Depth 10

$teacherId = $null
$testCompleted = $false

try {
    Write-Host "3. Creando profesor..."

    $createdResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/teachers" `
        -Method Post `
        -WebSession $global:webSession `
        -Headers $teacherHeaders `
        -ContentType "application/json" `
        -Body $createBody

    $teacher = $createdResponse.data
    $teacherId = [string]$teacher.id

    if ([string]::IsNullOrWhiteSpace($teacherId)) {
        throw "La API no devolvio el UUID del profesor."
    }

    Write-Host "   Profesor creado: $teacherId"
    Write-Host "   Numero: $($teacher.employee_number)"
    Write-Host "   Nombre: $($teacher.person.full_name)"

    Write-Host "4. Verificando que la sesion siga activa..."

    $currentUser = Invoke-RestMethod `
        -Uri "$backendUrl/api/auth/me" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $global:authHeaders

    Write-Host "   Sesion activa: $($currentUser.user.email)"

    Write-Host "5. Consultando profesor..."

    $showResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/teachers/$teacherId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $teacherHeaders

    if ($showResponse.data.id -ne $teacherId) {
        throw "La consulta devolvio un profesor diferente."
    }

    if (
        $showResponse.data.campus_id `
            -ne $campusId
    ) {
        throw "El profesor pertenece a otro plantel."
    }

    Write-Host "   Profesor consultado correctamente."
    Write-Host "   Correo: $($showResponse.data.person.email)"

    Write-Host "6. Actualizando profesor..."

    $updateBody = @{
        professional_license = "CED-TEST-$uniqueSuffix"
        status = "leave"
        person = @{
            first_name = "Profesor Actualizado"
            phone = "5551000099"
        }
    } | ConvertTo-Json -Depth 10

    $updatedResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/teachers/$teacherId" `
        -Method Patch `
        -WebSession $global:webSession `
        -Headers $teacherHeaders `
        -ContentType "application/json" `
        -Body $updateBody

    if ($updatedResponse.data.status -ne "leave") {
        throw "El estado del profesor no fue actualizado."
    }

    if (
        $updatedResponse.data.professional_license `
            -ne "CED-TEST-$uniqueSuffix"
    ) {
        throw "La cedula profesional no fue actualizada."
    }

    if (
        $updatedResponse.data.person.first_name `
            -ne "Profesor Actualizado"
    ) {
        throw "El nombre del profesor no fue actualizado."
    }

    if (
        $updatedResponse.data.person.phone `
            -ne "5551000099"
    ) {
        throw "El telefono del profesor no fue actualizado."
    }

    Write-Host "   Profesor actualizado correctamente."

    Write-Host "7. Buscando profesor en el listado..."

    $encodedSearch = [Uri]::EscapeDataString(
        $employeeNumber
    )

    $listResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/teachers?search=$encodedSearch&per_page=10" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $teacherHeaders

    $listedTeacher = $listResponse.data |
        Where-Object {
            $_.id -eq $teacherId
        } |
        Select-Object -First 1

    if (-not $listedTeacher) {
        throw "El profesor no aparecio en el listado filtrado."
    }

    Write-Host "   Profesor encontrado mediante busqueda."

    Write-Host "8. Eliminando profesor temporal..."

    Invoke-RestMethod `
        -Uri "$backendUrl/api/teachers/$teacherId" `
        -Method Delete `
        -WebSession $global:webSession `
        -Headers $teacherHeaders |
        Out-Null

    Write-Host "   Profesor eliminado."

    Write-Host "9. Confirmando eliminacion..."

    $notFoundConfirmed = $false

    try {
        Invoke-RestMethod `
            -Uri "$backendUrl/api/teachers/$teacherId" `
            -Method Get `
            -WebSession $global:webSession `
            -Headers $teacherHeaders |
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
        throw "El profesor eliminado todavia puede consultarse."
    }

    Write-Host "   Eliminacion confirmada."

    $teacherId = $null
    $testCompleted = $true
}
catch {
    Write-Host ""
    Write-Host "La prueba de profesores fallo." `
        -ForegroundColor Red

    Write-Host (
        Get-ErrorResponseBody -ErrorRecord $_
    ) -ForegroundColor Red

    throw
}
finally {
    # Si la prueba falla despues de crear el profesor,
    # se intenta eliminar automaticamente.

    if ($teacherId) {
        Write-Host ""
        Write-Host "Intentando limpiar el profesor temporal..." `
            -ForegroundColor Yellow

        try {
            Invoke-RestMethod `
                -Uri "$backendUrl/api/teachers/$teacherId" `
                -Method Delete `
                -WebSession $global:webSession `
                -Headers $teacherHeaders |
                Out-Null

            Write-Host "Profesor temporal eliminado." `
                -ForegroundColor Yellow
        }
        catch {
            Write-Host "No fue posible eliminar automaticamente el profesor $teacherId." `
                -ForegroundColor Red
        }
    }
}

if ($testCompleted) {
    Write-Host ""
    Write-Host "PRUEBA DE PROFESORES COMPLETADA CORRECTAMENTE" `
        -ForegroundColor Green
    Write-Host "Crear:      OK"
    Write-Host "Sesion:     OK"
    Write-Host "Consultar:  OK"
    Write-Host "Actualizar: OK"
    Write-Host "Buscar:     OK"
    Write-Host "Eliminar:   OK"
}