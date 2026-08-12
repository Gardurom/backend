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

Write-Host "3. Buscando ciclo escolar del plantel..."

$tinkerCommand = @"
echo App\Models\SchoolCycle::query()
    ->where('campus_id', '$campusId')
    ->orderByDesc('is_current')
    ->orderByDesc('starts_on')
    ->value('id');
"@

$schoolCycleOutput = & php artisan tinker `
    --execute="$tinkerCommand" 2>&1

if ($LASTEXITCODE -ne 0) {
    throw "No fue posible consultar el ciclo escolar: $schoolCycleOutput"
}

$schoolCycleId = (
    $schoolCycleOutput |
        Out-String
).Trim()

if (
    [string]::IsNullOrWhiteSpace($schoolCycleId) `
    -or $schoolCycleId -notmatch `
        '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$'
) {
    throw "No existe un ciclo escolar para el plantel seleccionado. Resultado: $schoolCycleId"
}

Write-Host "   Ciclo escolar ID: $schoolCycleId"

$groupHeaders = $global:authHeaders.Clone()
$groupHeaders["X-Campus-ID"] = $campusId

$uniqueSuffix = Get-Date -Format "yyyyMMddHHmmss"
$gradeLevel = "Prueba API $uniqueSuffix"
$section = "T$((Get-Date).ToString('HHmmss'))"

$createBody = @{
    school_cycle_id = $schoolCycleId
    grade_level = $gradeLevel
    section = $section
    shift = "evening"
    capacity = 25
    classroom = "Aula temporal"
    is_active = $true
} | ConvertTo-Json -Depth 10

$schoolGroupId = $null
$testCompleted = $false

try {
    Write-Host "4. Creando grupo escolar..."

    $createdResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/groups" `
        -Method Post `
        -WebSession $global:webSession `
        -Headers $groupHeaders `
        -ContentType "application/json" `
        -Body $createBody

    $schoolGroup = $createdResponse.data
    $schoolGroupId = [string]$schoolGroup.id

    if ([string]::IsNullOrWhiteSpace($schoolGroupId)) {
        throw "La API no devolvio el UUID del grupo."
    }

    Write-Host "   Grupo creado: $schoolGroupId"
    Write-Host "   Grado: $($schoolGroup.grade_level)"
    Write-Host "   Seccion: $($schoolGroup.section)"
    Write-Host "   Turno: $($schoolGroup.shift)"

    Write-Host "5. Verificando que la sesion siga activa..."

    $currentUser = Invoke-RestMethod `
        -Uri "$backendUrl/api/auth/me" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $global:authHeaders

    Write-Host "   Sesion activa: $($currentUser.user.email)"

    Write-Host "6. Consultando grupo..."

    $showResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/groups/$schoolGroupId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $groupHeaders

    if ($showResponse.data.id -ne $schoolGroupId) {
        throw "La consulta devolvio un grupo diferente."
    }

    if (
        $showResponse.data.school_cycle_id `
            -ne $schoolCycleId
    ) {
        throw "El grupo pertenece a un ciclo escolar diferente."
    }

    Write-Host "   Grupo consultado correctamente."

    Write-Host "7. Actualizando grupo..."

    $updateBody = @{
        capacity = 35
        classroom = "Aula actualizada"
        is_active = $false
    } | ConvertTo-Json -Depth 10

    $updatedResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/groups/$schoolGroupId" `
        -Method Patch `
        -WebSession $global:webSession `
        -Headers $groupHeaders `
        -ContentType "application/json" `
        -Body $updateBody

    if ($updatedResponse.data.capacity -ne 35) {
        throw "La capacidad del grupo no fue actualizada."
    }

    if (
        $updatedResponse.data.classroom `
            -ne "Aula actualizada"
    ) {
        throw "El aula del grupo no fue actualizada."
    }

    if ($updatedResponse.data.is_active -ne $false) {
        throw "El estado del grupo no fue actualizado."
    }

    Write-Host "   Grupo actualizado correctamente."

    Write-Host "8. Buscando grupo en el listado..."

    $encodedSearch = [Uri]::EscapeDataString(
        $gradeLevel
    )

    $listResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/groups?search=$encodedSearch&per_page=10" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $groupHeaders

    $listedGroup = $listResponse.data |
        Where-Object {
            $_.id -eq $schoolGroupId
        } |
        Select-Object -First 1

    if (-not $listedGroup) {
        throw "El grupo no aparecio en el listado filtrado."
    }

    Write-Host "   Grupo encontrado mediante busqueda."

    Write-Host "9. Eliminando grupo temporal..."

    Invoke-RestMethod `
        -Uri "$backendUrl/api/groups/$schoolGroupId" `
        -Method Delete `
        -WebSession $global:webSession `
        -Headers $groupHeaders |
        Out-Null

    Write-Host "   Grupo eliminado."

    Write-Host "10. Confirmando eliminacion..."

    $notFoundConfirmed = $false

    try {
        Invoke-RestMethod `
            -Uri "$backendUrl/api/groups/$schoolGroupId" `
            -Method Get `
            -WebSession $global:webSession `
            -Headers $groupHeaders |
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
        throw "El grupo eliminado todavia puede consultarse."
    }

    Write-Host "   Eliminacion confirmada."

    $schoolGroupId = $null
    $testCompleted = $true
}
catch {
    Write-Host ""
    Write-Host "La prueba de grupos fallo." `
        -ForegroundColor Red

    Write-Host (
        Get-ErrorResponseBody -ErrorRecord $_
    ) -ForegroundColor Red

    throw
}
finally {
    # Si la prueba falla despues de crear el grupo,
    # se intenta eliminar automaticamente.

    if ($schoolGroupId) {
        Write-Host ""
        Write-Host "Intentando limpiar el grupo temporal..." `
            -ForegroundColor Yellow

        try {
            Invoke-RestMethod `
                -Uri "$backendUrl/api/groups/$schoolGroupId" `
                -Method Delete `
                -WebSession $global:webSession `
                -Headers $groupHeaders |
                Out-Null

            Write-Host "Grupo temporal eliminado." `
                -ForegroundColor Yellow
        }
        catch {
            Write-Host "No fue posible eliminar automaticamente el grupo $schoolGroupId." `
                -ForegroundColor Red
        }
    }
}

if ($testCompleted) {
    Write-Host ""
    Write-Host "PRUEBA DE GRUPOS COMPLETADA CORRECTAMENTE" `
        -ForegroundColor Green
    Write-Host "Crear:      OK"
    Write-Host "Sesion:     OK"
    Write-Host "Consultar:  OK"
    Write-Host "Actualizar: OK"
    Write-Host "Buscar:     OK"
    Write-Host "Eliminar:   OK"
}