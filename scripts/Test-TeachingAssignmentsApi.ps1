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

Write-Host "2. Obteniendo plantel..."

$campusResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/campuses?is_active=1&per_page=100" `
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

$campusHeaders = $global:authHeaders.Clone()
$campusHeaders["X-Campus-ID"] = $campusId

Write-Host "3. Obteniendo grupo activo..."

$groupsResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/groups?is_active=1&per_page=100" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $campusHeaders

$schoolGroup = $groupsResponse.data |
    Select-Object -First 1

if (-not $schoolGroup) {
    throw "No existe ningun grupo activo en el plantel."
}

$schoolGroupId = [string] $schoolGroup.id

Write-Host "   Grupo ID: $schoolGroupId"
Write-Host "   Grupo: $($schoolGroup.grade_level) $($schoolGroup.section)"

Write-Host "4. Obteniendo profesor activo..."

$teachersResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/teachers?status=active&per_page=100" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $campusHeaders

$teacher = $teachersResponse.data |
    Select-Object -First 1

if (-not $teacher) {
    throw "No existe ningun profesor activo en el plantel."
}

$teacherId = [string] $teacher.id

Write-Host "   Profesor ID: $teacherId"
Write-Host "   Profesor: $($teacher.person.full_name)"

$uniqueSuffix = Get-Date -Format "yyyyMMddHHmmss"
$subjectCode = "TA-$uniqueSuffix"

$subjectId = $null
$assignmentId = $null
$testCompleted = $false

try {
    Write-Host "5. Creando materia temporal..."

    $subjectBody = @{
        campus_id = $campusId
        code = $subjectCode
        name = "Materia asignacion $uniqueSuffix"
        description = "Materia temporal para probar asignaciones docentes."
        weekly_hours = 3.5
        is_active = $true
    } | ConvertTo-Json -Depth 10

    $subjectResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/subjects" `
        -Method Post `
        -WebSession $global:webSession `
        -Headers $campusHeaders `
        -ContentType "application/json" `
        -Body $subjectBody

    $subjectId = [string] $subjectResponse.data.id

    if ([string]::IsNullOrWhiteSpace($subjectId)) {
        throw "La API no devolvio el UUID de la materia temporal."
    }

    Write-Host "   Materia creada: $subjectId"
    Write-Host "   Codigo: $subjectCode"

    Write-Host "6. Creando asignacion docente..."

    $startsOn = (Get-Date).ToString("yyyy-MM-dd")
    $endsOn = (Get-Date).AddDays(30).ToString(
        "yyyy-MM-dd"
    )

    $assignmentBody = @{
        school_group_id = $schoolGroupId
        subject_id = $subjectId
        teacher_id = $teacherId
        starts_on = $startsOn
        ends_on = $endsOn
        status = "active"
    } | ConvertTo-Json -Depth 10

    $createdResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/teaching-assignments" `
        -Method Post `
        -WebSession $global:webSession `
        -Headers $campusHeaders `
        -ContentType "application/json" `
        -Body $assignmentBody

    $assignment = $createdResponse.data
    $assignmentId = [string] $assignment.id

    if ([string]::IsNullOrWhiteSpace($assignmentId)) {
        throw "La API no devolvio el UUID de la asignacion."
    }

    Write-Host "   Asignacion creada: $assignmentId"
    Write-Host "   Profesor: $($assignment.teacher.full_name)"
    Write-Host "   Materia: $($assignment.subject.name)"
    Write-Host "   Estado: $($assignment.status)"

    if ($assignment.school_group_id -ne $schoolGroupId) {
        throw "La asignacion devolvio un grupo incorrecto."
    }

    if ($assignment.subject_id -ne $subjectId) {
        throw "La asignacion devolvio una materia incorrecta."
    }

    if ($assignment.teacher_id -ne $teacherId) {
        throw "La asignacion devolvio un profesor incorrecto."
    }

    Write-Host "7. Verificando que la sesion siga activa..."

    $currentUser = Invoke-RestMethod `
        -Uri "$backendUrl/api/auth/me" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $global:authHeaders

    Write-Host "   Sesion activa: $($currentUser.user.email)"

    Write-Host "8. Consultando asignacion..."

    $showResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/teaching-assignments/$assignmentId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $campusHeaders

    if ($showResponse.data.id -ne $assignmentId) {
        throw "La consulta devolvio una asignacion diferente."
    }

    if (
        $showResponse.data.school_group.school_cycle.campus_id `
            -ne $campusId
    ) {
        throw "La asignacion pertenece a otro plantel."
    }

    Write-Host "   Asignacion consultada correctamente."

    Write-Host "9. Actualizando asignacion..."

    $updatedEndsOn = (Get-Date).AddDays(60).ToString(
        "yyyy-MM-dd"
    )

    $updateBody = @{
        ends_on = $updatedEndsOn
        status = "completed"
    } | ConvertTo-Json -Depth 10

    $updatedResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/teaching-assignments/$assignmentId" `
        -Method Patch `
        -WebSession $global:webSession `
        -Headers $campusHeaders `
        -ContentType "application/json" `
        -Body $updateBody

    if ($updatedResponse.data.status -ne "completed") {
        throw "El estado de la asignacion no fue actualizado."
    }

    if ($updatedResponse.data.ends_on -ne $updatedEndsOn) {
        throw "La fecha final de la asignacion no fue actualizada."
    }

    Write-Host "   Asignacion actualizada correctamente."

    Write-Host "10. Buscando asignacion en el listado..."

    $encodedSearch = [Uri]::EscapeDataString(
        $subjectCode
    )

    $listResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/teaching-assignments?search=$encodedSearch&per_page=100" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $campusHeaders

    $listedAssignment = $listResponse.data |
        Where-Object {
            $_.id -eq $assignmentId
        } |
        Select-Object -First 1

    if (-not $listedAssignment) {
        throw "La asignacion no aparecio en el listado."
    }

    Write-Host "   Asignacion encontrada mediante busqueda."

    Write-Host "11. Probando filtro por profesor..."

    $teacherFilterResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/teaching-assignments?teacher_id=$teacherId&per_page=100" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $campusHeaders

    $teacherAssignment = $teacherFilterResponse.data |
        Where-Object {
            $_.id -eq $assignmentId
        } |
        Select-Object -First 1

    if (-not $teacherAssignment) {
        throw "El filtro por profesor no devolvio la asignacion."
    }

    Write-Host "   Filtro por profesor: correcto."

    Write-Host "12. Probando filtro por estado..."

    $statusFilterResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/teaching-assignments?status=completed&per_page=100" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $campusHeaders

    $completedAssignment = $statusFilterResponse.data |
        Where-Object {
            $_.id -eq $assignmentId
        } |
        Select-Object -First 1

    if (-not $completedAssignment) {
        throw "El filtro por estado no devolvio la asignacion."
    }

    Write-Host "   Filtro por estado: correcto."

    Write-Host "13. Eliminando asignacion temporal..."

    Invoke-RestMethod `
        -Uri "$backendUrl/api/teaching-assignments/$assignmentId" `
        -Method Delete `
        -WebSession $global:webSession `
        -Headers $campusHeaders |
        Out-Null

    Write-Host "   Asignacion eliminada."

    Write-Host "14. Confirmando eliminacion..."

    $notFoundConfirmed = $false

    try {
        Invoke-RestMethod `
            -Uri "$backendUrl/api/teaching-assignments/$assignmentId" `
            -Method Get `
            -WebSession $global:webSession `
            -Headers $campusHeaders |
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
        throw "La asignacion eliminada todavia puede consultarse."
    }

    Write-Host "   Eliminacion confirmada."

    $assignmentId = $null

    Write-Host "15. Eliminando materia temporal..."

    Invoke-RestMethod `
        -Uri "$backendUrl/api/subjects/$subjectId" `
        -Method Delete `
        -WebSession $global:webSession `
        -Headers $campusHeaders |
        Out-Null

    Write-Host "   Materia temporal eliminada."

    $subjectId = $null
    $testCompleted = $true
}
catch {
    Write-Host ""
    Write-Host "LA PRUEBA DE ASIGNACIONES FALLO" `
        -ForegroundColor Red

    Write-Host (
        Get-ErrorResponseBody -ErrorRecord $_
    ) -ForegroundColor Red

    throw
}
finally {
    if ($assignmentId) {
        Write-Host ""
        Write-Host "Intentando limpiar la asignacion temporal..." `
            -ForegroundColor Yellow

        try {
            Invoke-RestMethod `
                -Uri "$backendUrl/api/teaching-assignments/$assignmentId" `
                -Method Delete `
                -WebSession $global:webSession `
                -Headers $campusHeaders |
                Out-Null

            Write-Host "Asignacion temporal eliminada." `
                -ForegroundColor Yellow
        }
        catch {
            Write-Host "No fue posible eliminar la asignacion $assignmentId." `
                -ForegroundColor Red
        }
    }

    if ($subjectId) {
        Write-Host "Intentando limpiar la materia temporal..." `
            -ForegroundColor Yellow

        try {
            Invoke-RestMethod `
                -Uri "$backendUrl/api/subjects/$subjectId" `
                -Method Delete `
                -WebSession $global:webSession `
                -Headers $campusHeaders |
                Out-Null

            Write-Host "Materia temporal eliminada." `
                -ForegroundColor Yellow
        }
        catch {
            Write-Host "No fue posible eliminar la materia $subjectId." `
                -ForegroundColor Red
        }
    }
}

if ($testCompleted) {
    Write-Host ""
    Write-Host "PRUEBA DE ASIGNACIONES COMPLETADA CORRECTAMENTE" `
        -ForegroundColor Green

    Write-Host "Crear:             OK"
    Write-Host "Sesion:            OK"
    Write-Host "Consultar:         OK"
    Write-Host "Actualizar:        OK"
    Write-Host "Buscar:            OK"
    Write-Host "Filtrar profesor:  OK"
    Write-Host "Filtrar estado:    OK"
    Write-Host "Eliminar:          OK"
    Write-Host "Limpieza:          OK"
}