$ErrorActionPreference = "Stop"

$backendUrl = "http://127.0.0.1:8000"

$campusId = "019fd365-2df9-7dae-89a0-2ced47c13278"

$teachingAssignmentId = `
    "019fda40-20f9-70d9-a492-ff77bfcefca9"

$gradingPeriodId = `
    "019fda73-3413-7188-a442-3ff69a7fc4bf"

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

        $stream = $response.GetResponseStream()
        $reader = New-Object System.IO.StreamReader($stream)
        $body = $reader.ReadToEnd()
        $reader.Dispose()

        return $body
    }
    catch {
        return $ErrorRecord.Exception.Message
    }
}

$assessmentHeaders = $global:authHeaders.Clone()
$assessmentHeaders["X-Campus-ID"] = $campusId

$uniqueSuffix = Get-Date -Format "yyyyMMddHHmmss"
$assessmentName = "Evaluacion API $uniqueSuffix"

$assessmentId = $null
$testCompleted = $false

try {
    Write-Host "1. Comprobando sesion autenticada..."

    $currentUser = Invoke-RestMethod `
        -Uri "$backendUrl/api/auth/me" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $global:authHeaders

    Write-Host "   Usuario: $($currentUser.user.email)"

    Write-Host "2. Comprobando asignacion docente..."

    $assignmentResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/teaching-assignments/$teachingAssignmentId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $assessmentHeaders

    $assignment = $assignmentResponse.data

    if ($assignment.id -ne $teachingAssignmentId) {
        throw "La API devolvio una asignacion docente diferente."
    }

    if (
        $assignment.school_group.school_cycle.campus_id `
            -ne $campusId
    ) {
        throw "La asignacion docente no pertenece al plantel esperado."
    }

    Write-Host "   Asignacion ID: $($assignment.id)"
    Write-Host "   Materia: $($assignment.subject.name)"
    Write-Host "   Profesor: $($assignment.teacher.full_name)"
    Write-Host "   Grupo: $($assignment.school_group.grade_level) $($assignment.school_group.section)"

    Write-Host "3. Creando evaluacion temporal..."

    $dueAt = (Get-Date).AddDays(7).ToString(
        "yyyy-MM-ddTHH:mm:sszzz"
    )

    $createBody = @{
        teaching_assignment_id = $teachingAssignmentId
        grading_period_id = $gradingPeriodId
        name = $assessmentName
        description = "Evaluacion temporal creada por la prueba API."
        type = "quiz"
        maximum_score = 100
        weight = 10
        due_at = $dueAt
        status = "cancelled"
    } | ConvertTo-Json -Depth 10

    $createdResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/assessments" `
        -Method Post `
        -WebSession $global:webSession `
        -Headers $assessmentHeaders `
        -ContentType "application/json" `
        -Body $createBody

    $assessment = $createdResponse.data
    $assessmentId = [string] $assessment.id

    if ([string]::IsNullOrWhiteSpace($assessmentId)) {
        throw "La API no devolvio el UUID de la evaluacion."
    }

    if (
        $assessment.teaching_assignment_id `
            -ne $teachingAssignmentId
    ) {
        throw "La evaluacion devolvio una asignacion incorrecta."
    }

    if (
        $assessment.grading_period_id `
            -ne $gradingPeriodId
    ) {
        throw "La evaluacion devolvio un periodo incorrecto."
    }

    if ($assessment.status -ne "cancelled") {
        throw "La evaluacion no fue creada con estado cancelled."
    }

    Write-Host "   Evaluacion creada: $assessmentId"
    Write-Host "   Nombre: $($assessment.name)"
    Write-Host "   Tipo: $($assessment.type)"
    Write-Host "   Estado: $($assessment.status)"

    Write-Host "4. Verificando que la sesion siga activa..."

    $currentUser = Invoke-RestMethod `
        -Uri "$backendUrl/api/auth/me" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $global:authHeaders

    Write-Host "   Sesion activa: $($currentUser.user.email)"

    Write-Host "5. Consultando evaluacion..."

    $showResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/assessments/$assessmentId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $assessmentHeaders

    if ($showResponse.data.id -ne $assessmentId) {
        throw "La consulta devolvio una evaluacion diferente."
    }

    if (
        $showResponse.data.grading_period.id `
            -ne $gradingPeriodId
    ) {
        throw "La evaluacion tiene un periodo diferente."
    }

    if (
        $showResponse.data.teaching_assignment.id `
            -ne $teachingAssignmentId
    ) {
        throw "La evaluacion tiene una asignacion diferente."
    }

    Write-Host "   Evaluacion consultada correctamente."
    Write-Host "   Periodo: $($showResponse.data.grading_period.name)"
    Write-Host "   Materia: $($showResponse.data.teaching_assignment.subject.name)"

    Write-Host "6. Actualizando evaluacion..."

    $updatedName = "Evaluacion actualizada $uniqueSuffix"

    $updatedDueAt = (Get-Date).AddDays(14).ToString(
        "yyyy-MM-ddTHH:mm:sszzz"
    )

    $updateBody = @{
        name = $updatedName
        description = "Evaluacion actualizada correctamente."
        type = "exam"
        maximum_score = 50
        weight = 15
        due_at = $updatedDueAt
        status = "cancelled"
    } | ConvertTo-Json -Depth 10

    $updatedResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/assessments/$assessmentId" `
        -Method Patch `
        -WebSession $global:webSession `
        -Headers $assessmentHeaders `
        -ContentType "application/json" `
        -Body $updateBody

    if ($updatedResponse.data.name -ne $updatedName) {
        throw "El nombre de la evaluacion no fue actualizado."
    }

    if ($updatedResponse.data.type -ne "exam") {
        throw "El tipo de evaluacion no fue actualizado."
    }

    if (
        [decimal] $updatedResponse.data.maximum_score `
            -ne [decimal] 50
    ) {
        throw "La puntuacion maxima no fue actualizada."
    }

    if (
        [decimal] $updatedResponse.data.weight `
            -ne [decimal] 15
    ) {
        throw "La ponderacion no fue actualizada."
    }

    if ($updatedResponse.data.status -ne "cancelled") {
        throw "El estado de la evaluacion no fue actualizado."
    }

    Write-Host "   Evaluacion actualizada correctamente."

    Write-Host "7. Buscando evaluacion en el listado..."

    $encodedSearch = [Uri]::EscapeDataString(
        $updatedName
    )

    $listResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/assessments?search=$encodedSearch&per_page=100" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $assessmentHeaders

    $listedAssessment = $listResponse.data |
        Where-Object {
            $_.id -eq $assessmentId
        } |
        Select-Object -First 1

    if (-not $listedAssessment) {
        throw "La evaluacion no aparecio en la busqueda."
    }

    Write-Host "   Evaluacion encontrada mediante busqueda."

    Write-Host "8. Probando filtro por asignacion..."

    $assignmentFilterResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/assessments?teaching_assignment_id=$teachingAssignmentId&per_page=100" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $assessmentHeaders

    $assignmentAssessment = $assignmentFilterResponse.data |
        Where-Object {
            $_.id -eq $assessmentId
        } |
        Select-Object -First 1

    if (-not $assignmentAssessment) {
        throw "El filtro por asignacion no devolvio la evaluacion."
    }

    Write-Host "   Filtro por asignacion: correcto."

    Write-Host "9. Probando filtro por periodo..."

    $periodFilterResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/assessments?grading_period_id=$gradingPeriodId&per_page=100" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $assessmentHeaders

    $periodAssessment = $periodFilterResponse.data |
        Where-Object {
            $_.id -eq $assessmentId
        } |
        Select-Object -First 1

    if (-not $periodAssessment) {
        throw "El filtro por periodo no devolvio la evaluacion."
    }

    Write-Host "   Filtro por periodo: correcto."

    Write-Host "10. Probando filtro por tipo..."

    $typeFilterResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/assessments?type=exam&per_page=100" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $assessmentHeaders

    $typeAssessment = $typeFilterResponse.data |
        Where-Object {
            $_.id -eq $assessmentId
        } |
        Select-Object -First 1

    if (-not $typeAssessment) {
        throw "El filtro por tipo no devolvio la evaluacion."
    }

    Write-Host "   Filtro por tipo: correcto."

    Write-Host "11. Probando filtro por estado..."

    $statusFilterResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/assessments?status=cancelled&per_page=100" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $assessmentHeaders

    $statusAssessment = $statusFilterResponse.data |
        Where-Object {
            $_.id -eq $assessmentId
        } |
        Select-Object -First 1

    if (-not $statusAssessment) {
        throw "El filtro por estado no devolvio la evaluacion."
    }

    Write-Host "   Filtro por estado: correcto."

    Write-Host "12. Eliminando evaluacion temporal..."

    Invoke-RestMethod `
        -Uri "$backendUrl/api/assessments/$assessmentId" `
        -Method Delete `
        -WebSession $global:webSession `
        -Headers $assessmentHeaders |
        Out-Null

    Write-Host "   Evaluacion eliminada."

    Write-Host "13. Confirmando eliminacion..."

    $notFoundConfirmed = $false

    try {
        Invoke-RestMethod `
            -Uri "$backendUrl/api/assessments/$assessmentId" `
            -Method Get `
            -WebSession $global:webSession `
            -Headers $assessmentHeaders |
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
        throw "La evaluacion eliminada todavia puede consultarse."
    }

    Write-Host "   Eliminacion confirmada."

    $assessmentId = $null
    $testCompleted = $true
}
catch {
    Write-Host ""
    Write-Host "LA PRUEBA DE EVALUACIONES FALLO" `
        -ForegroundColor Red

    Write-Host (
        Get-ErrorResponseBody -ErrorRecord $_
    ) -ForegroundColor Red

    throw
}
finally {
    if ($assessmentId) {
        Write-Host ""
        Write-Host "Intentando limpiar la evaluacion temporal..." `
            -ForegroundColor Yellow

        try {
            Invoke-RestMethod `
                -Uri "$backendUrl/api/assessments/$assessmentId" `
                -Method Delete `
                -WebSession $global:webSession `
                -Headers $assessmentHeaders |
                Out-Null

            Write-Host "Evaluacion temporal eliminada." `
                -ForegroundColor Yellow
        }
        catch {
            Write-Host "No fue posible eliminar automaticamente la evaluacion $assessmentId." `
                -ForegroundColor Red
        }
    }
}

if ($testCompleted) {
    Write-Host ""
    Write-Host "PRUEBA DE EVALUACIONES COMPLETADA CORRECTAMENTE" `
        -ForegroundColor Green

    Write-Host "Crear:               OK"
    Write-Host "Sesion:              OK"
    Write-Host "Consultar:           OK"
    Write-Host "Actualizar:          OK"
    Write-Host "Buscar:              OK"
    Write-Host "Filtrar asignacion:  OK"
    Write-Host "Filtrar periodo:     OK"
    Write-Host "Filtrar tipo:        OK"
    Write-Host "Filtrar estado:      OK"
    Write-Host "Eliminar:            OK"
    Write-Host "Limpieza:            OK"
}