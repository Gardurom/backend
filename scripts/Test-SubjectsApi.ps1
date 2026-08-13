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

$campusId = [string] $campus.id

Write-Host "   Plantel: $($campus.name)"
Write-Host "   Campus ID: $campusId"

$subjectHeaders = $global:authHeaders.Clone()
$subjectHeaders["X-Campus-ID"] = $campusId

$uniqueSuffix = Get-Date -Format "yyyyMMddHHmmss"
$subjectCode = "TEST-MAT-$uniqueSuffix"
$subjectName = "Materia temporal $uniqueSuffix"

$createBody = @{
    campus_id = $campusId
    code = $subjectCode
    name = $subjectName
    description = "Materia creada por la prueba automatizada."
    weekly_hours = 4.5
    is_active = $true
} | ConvertTo-Json -Depth 10

$subjectId = $null
$testCompleted = $false

try {
    Write-Host "3. Creando materia..."

    $createdResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/subjects" `
        -Method Post `
        -WebSession $global:webSession `
        -Headers $subjectHeaders `
        -ContentType "application/json" `
        -Body $createBody

    $subject = $createdResponse.data
    $subjectId = [string] $subject.id

    if ([string]::IsNullOrWhiteSpace($subjectId)) {
        throw "La API no devolvio el UUID de la materia."
    }

    Write-Host "   Materia creada: $subjectId"
    Write-Host "   Codigo: $($subject.code)"
    Write-Host "   Nombre: $($subject.name)"

    Write-Host "4. Verificando que la sesion siga activa..."

    $currentUser = Invoke-RestMethod `
        -Uri "$backendUrl/api/auth/me" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $global:authHeaders

    Write-Host "   Sesion activa: $($currentUser.user.email)"

    Write-Host "5. Consultando materia..."

    $showResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/subjects/$subjectId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $subjectHeaders

    if ($showResponse.data.id -ne $subjectId) {
        throw "La consulta devolvio una materia diferente."
    }

    if ($showResponse.data.campus_id -ne $campusId) {
        throw "La materia pertenece a otro plantel."
    }

    Write-Host "   Materia consultada correctamente."

    Write-Host "6. Actualizando materia..."

    $updateBody = @{
        name = "Materia actualizada $uniqueSuffix"
        description = "Materia actualizada correctamente."
        weekly_hours = 6.25
        is_active = $false
    } | ConvertTo-Json -Depth 10

    $updatedResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/subjects/$subjectId" `
        -Method Patch `
        -WebSession $global:webSession `
        -Headers $subjectHeaders `
        -ContentType "application/json" `
        -Body $updateBody

    if (
        $updatedResponse.data.name `
            -ne "Materia actualizada $uniqueSuffix"
    ) {
        throw "El nombre de la materia no fue actualizado."
    }

    if (
        [decimal] $updatedResponse.data.weekly_hours `
            -ne [decimal] 6.25
    ) {
        throw "Las horas semanales no fueron actualizadas."
    }

    if (
        [bool] $updatedResponse.data.is_active `
            -ne $false
    ) {
        throw "El estado de la materia no fue actualizado."
    }

    Write-Host "   Materia actualizada correctamente."

    Write-Host "7. Buscando materia en el listado..."

    $encodedSearch = [Uri]::EscapeDataString(
        $subjectCode
    )

    $listResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/subjects?search=$encodedSearch&per_page=10" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $subjectHeaders

    $listedSubject = $listResponse.data |
        Where-Object {
            $_.id -eq $subjectId
        } |
        Select-Object -First 1

    if (-not $listedSubject) {
        throw "La materia no aparecio en el listado filtrado."
    }

    Write-Host "   Materia encontrada mediante busqueda."

    Write-Host "8. Eliminando materia temporal..."

    Invoke-RestMethod `
        -Uri "$backendUrl/api/subjects/$subjectId" `
        -Method Delete `
        -WebSession $global:webSession `
        -Headers $subjectHeaders |
        Out-Null

    Write-Host "   Materia eliminada."

    Write-Host "9. Confirmando eliminacion..."

    $notFoundConfirmed = $false

    try {
        Invoke-RestMethod `
            -Uri "$backendUrl/api/subjects/$subjectId" `
            -Method Get `
            -WebSession $global:webSession `
            -Headers $subjectHeaders |
            Out-Null
    }
    catch {
        if (
            $_.Exception.Response `
            -and [int] $_.Exception.Response.StatusCode -eq 404
        ) {
            $notFoundConfirmed = $true
        }
        else {
            throw
        }
    }

    if (-not $notFoundConfirmed) {
        throw "La materia eliminada todavia puede consultarse."
    }

    Write-Host "   Eliminacion confirmada."

    $subjectId = $null
    $testCompleted = $true
}
catch {
    Write-Host ""
    Write-Host "La prueba de materias fallo." `
        -ForegroundColor Red

    Write-Host (
        Get-ErrorResponseBody -ErrorRecord $_
    ) -ForegroundColor Red

    throw
}
finally {
    if ($subjectId) {
        Write-Host ""
        Write-Host "Intentando limpiar la materia temporal..." `
            -ForegroundColor Yellow

        try {
            Invoke-RestMethod `
                -Uri "$backendUrl/api/subjects/$subjectId" `
                -Method Delete `
                -WebSession $global:webSession `
                -Headers $subjectHeaders |
                Out-Null

            Write-Host "Materia temporal eliminada." `
                -ForegroundColor Yellow
        }
        catch {
            Write-Host "No fue posible eliminar automaticamente la materia $subjectId." `
                -ForegroundColor Red
        }
    }
}

if ($testCompleted) {
    Write-Host ""
    Write-Host "PRUEBA DE MATERIAS COMPLETADA CORRECTAMENTE" `
        -ForegroundColor Green
    Write-Host "Crear:      OK"
    Write-Host "Sesion:     OK"
    Write-Host "Consultar:  OK"
    Write-Host "Actualizar: OK"
    Write-Host "Buscar:     OK"
    Write-Host "Eliminar:   OK"
}