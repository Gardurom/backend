$ErrorActionPreference = "Stop"

$backendUrl = "http://127.0.0.1:8000"

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path

$stateDirectory = Join-Path `
    $scriptRoot `
    ".test-state"

$stateFile = Join-Path `
    $stateDirectory `
    "assessments.json"

Write-Host ""
Write-Host "========================================"
Write-Host " CONTROL ESCOLAR - CREACION DE DATOS API"
Write-Host " MODULO: EVALUACIONES"
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
No existen encabezados autenticados.

Ejecuta primero:

. .\scripts\Test-Auth.ps1
"@
}

if (Test-Path -LiteralPath $stateFile) {
    throw @"
Ya existe un archivo de estado para Assessments:

$stateFile

No se crearan nuevos datos temporales.

Ejecuta primero el cleanup correspondiente
o revisa el estado existente.
"@
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

Write-Host "1. Verificando sesion autenticada..."

Remove-Variable currentUser `
    -ErrorAction SilentlyContinue

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

Write-Host "2. Obteniendo plantel activo..."

Remove-Variable campusResponse `
    -ErrorAction SilentlyContinue

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

$campusId = [string]$campus.id

if ([string]::IsNullOrWhiteSpace($campusId)) {
    throw "El plantel seleccionado no contiene UUID."
}

Write-Host "   OK"
Write-Host "   Plantel: $($campus.name)"
Write-Host "   Campus ID: $campusId"
Write-Host ""

$campusHeaders = $global:authHeaders.Clone()

$campusHeaders["Accept"] = "application/json"
$campusHeaders["X-Campus-ID"] = $campusId

Write-Host "3. Obteniendo asignacion docente existente..."

Remove-Variable assignmentsResponse `
    -ErrorAction SilentlyContinue

$assignmentsResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/teaching-assignments?per_page=100" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $campusHeaders

$teachingAssignment = $assignmentsResponse.data |
    Where-Object {
        [string]$_.status -eq "active"
    } |
    Select-Object -First 1

if (-not $teachingAssignment) {
    $teachingAssignment = $assignmentsResponse.data |
        Select-Object -First 1
}

if (-not $teachingAssignment) {
    throw @"
No existe ninguna asignacion docente disponible
en el plantel para preparar la evaluacion.
"@
}

$teachingAssignmentId = [string]$teachingAssignment.id

if (
    [string]::IsNullOrWhiteSpace(
        $teachingAssignmentId
    )
) {
    throw "La asignacion docente no contiene UUID."
}

Write-Host "   OK"
Write-Host "   Teaching Assignment ID: $teachingAssignmentId"
Write-Host "   Materia: $($teachingAssignment.subject.name)"
Write-Host "   Profesor: $($teachingAssignment.teacher.full_name)"
Write-Host ""

Write-Host "4. Confirmando plantel de la asignacion..."

Remove-Variable assignmentResponse `
    -ErrorAction SilentlyContinue

$assignmentResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/teaching-assignments/$teachingAssignmentId" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $campusHeaders

$assignment = $assignmentResponse.data

if (-not $assignment) {
    throw "La API no devolvio la asignacion docente."
}

if (
    [string]$assignment.id `
        -ne $teachingAssignmentId
) {
    throw "La API devolvio una asignacion diferente."
}

if (
    [string]$assignment.school_group.school_cycle.campus_id `
        -ne $campusId
) {
    throw "La asignacion docente pertenece a otro plantel."
}

Write-Host "   OK"
Write-Host "   Grupo: $($assignment.school_group.grade_level) $($assignment.school_group.section)"
Write-Host ""

Write-Host "5. Obteniendo periodo de calificacion..."

$gradingPeriodCode = @'
$period = App\Models\GradingPeriod::query()
    ->whereHas('schoolCycle', function ($query) {
        $query->where('campus_id', '__CAMPUS_ID__');
    })
    ->orderBy('starts_on')
    ->first();

if ($period) {
    echo json_encode([
        'id' => $period->id,
        'name' => $period->name,
    ]);
}
'@

$gradingPeriodCode = $gradingPeriodCode.Replace(
    "__CAMPUS_ID__",
    $campusId
)

Remove-Variable gradingPeriodOutput `
    -ErrorAction SilentlyContinue

$gradingPeriodOutput = php artisan tinker `
    --execute="$gradingPeriodCode" 2>&1 |
    Out-String

$gradingPeriodOutput = $gradingPeriodOutput.Trim()

if (
    [string]::IsNullOrWhiteSpace(
        $gradingPeriodOutput
    )
) {
    throw @"
No existe ningun periodo de calificacion
para el plantel seleccionado.
"@
}

$jsonMatch = [regex]::Match(
    $gradingPeriodOutput,
    '\{[^\r\n]*"id"[^\r\n]*\}'
)

if (-not $jsonMatch.Success) {
    throw @"
No fue posible interpretar el periodo de calificacion.

Salida de Tinker:

$gradingPeriodOutput
"@
}

$gradingPeriod = $jsonMatch.Value |
    ConvertFrom-Json

$gradingPeriodId = [string]$gradingPeriod.id
$gradingPeriodName = [string]$gradingPeriod.name

if (
    [string]::IsNullOrWhiteSpace(
        $gradingPeriodId
    )
) {
    throw "El periodo de calificacion no contiene UUID."
}

Write-Host "   OK"
Write-Host "   Grading Period ID: $gradingPeriodId"
Write-Host "   Periodo: $gradingPeriodName"
Write-Host ""

$uniqueSuffix = Get-Date -Format "yyyyMMddHHmmssfff"

$assessmentName = "Evaluacion API $uniqueSuffix"

$assessmentId = $null
$creationSucceeded = $false

try {
    Write-Host "6. Creando evaluacion temporal..."

    $dueAt = (Get-Date).AddDays(7).ToString(
        "yyyy-MM-ddTHH:mm:sszzz"
    )

    $createBodyObject = @{
        teaching_assignment_id = $teachingAssignmentId

        grading_period_id = $gradingPeriodId

        name = $assessmentName

        description = "Evaluacion temporal creada por pruebas de API."

        type = "quiz"

        maximum_score = 100

        weight = 10

        due_at = $dueAt

        status = "cancelled"
    }

    $createBody = $createBodyObject |
        ConvertTo-Json -Depth 10

    Remove-Variable createdResponse `
        -ErrorAction SilentlyContinue

    $createdResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/assessments" `
        -Method Post `
        -WebSession $global:webSession `
        -Headers $campusHeaders `
        -ContentType "application/json" `
        -Body $createBody

    if (-not $createdResponse.data) {
        throw "La API no devolvio data para la evaluacion."
    }

    $assessment = $createdResponse.data
    $assessmentId = [string]$assessment.id

    if (
        [string]::IsNullOrWhiteSpace(
            $assessmentId
        )
    ) {
        throw "La API no devolvio el UUID de la evaluacion."
    }

    if (
        [string]$assessment.teaching_assignment_id `
            -ne $teachingAssignmentId
    ) {
        throw "La evaluacion devolvio una asignacion incorrecta."
    }

    if (
        [string]$assessment.grading_period_id `
            -ne $gradingPeriodId
    ) {
        throw "La evaluacion devolvio un periodo incorrecto."
    }

    if (
        [string]$assessment.name `
            -ne $assessmentName
    ) {
        throw "La evaluacion devolvio un nombre incorrecto."
    }

    if (
        [string]$assessment.type `
            -ne "quiz"
    ) {
        throw "La evaluacion no fue creada como quiz."
    }

    if (
        [decimal]$assessment.maximum_score `
            -ne [decimal]100
    ) {
        throw "La puntuacion maxima creada es incorrecta."
    }

    if (
        [decimal]$assessment.weight `
            -ne [decimal]10
    ) {
        throw "La ponderacion creada es incorrecta."
    }

    if (
        [string]$assessment.status `
            -ne "cancelled"
    ) {
        throw "La evaluacion no fue creada como cancelled."
    }

    Write-Host "   OK"
    Write-Host "   Assessment ID: $assessmentId"
    Write-Host "   Nombre:        $assessmentName"
    Write-Host "   Tipo:          quiz"
    Write-Host "   Estado:        cancelled"
    Write-Host ""

    Write-Host "7. Confirmando evaluacion mediante GET..."

    Remove-Variable showResponse `
        -ErrorAction SilentlyContinue

    $showResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/assessments/$assessmentId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $campusHeaders

    if (-not $showResponse.data) {
        throw "La API no devolvio la evaluacion consultada."
    }

    if (
        [string]$showResponse.data.id `
            -ne $assessmentId
    ) {
        throw "El GET devolvio una evaluacion diferente."
    }

    if (
        [string]$showResponse.data.teaching_assignment.id `
            -ne $teachingAssignmentId
    ) {
        throw "La asignacion del GET no coincide."
    }

    if (
        [string]$showResponse.data.grading_period.id `
            -ne $gradingPeriodId
    ) {
        throw "El periodo del GET no coincide."
    }

    Write-Host "   OK"
    Write-Host ""

    Write-Host "8. Creando archivo de estado..."

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
        module = "assessments"

        created_at = (
            Get-Date
        ).ToString("o")

        backend_url = $backendUrl

        campus = [ordered]@{
            id = $campusId
            name = [string]$campus.name
        }

        dependencies = [ordered]@{
            teaching_assignment = [ordered]@{
                id = $teachingAssignmentId
                subject_id = [string]$assignment.subject_id
                teacher_id = [string]$assignment.teacher_id
                school_group_id = [string]$assignment.school_group_id
                created_by_api_test = $false
            }

            grading_period = [ordered]@{
                id = $gradingPeriodId
                name = $gradingPeriodName
                created_by_api_test = $false
            }
        }

        assessment = [ordered]@{
            id = $assessmentId
            teaching_assignment_id = $teachingAssignmentId
            grading_period_id = $gradingPeriodId
            name = $assessmentName
            type = "quiz"
            maximum_score = 100
            weight = 10
            due_at = $dueAt
            status = "cancelled"
        }

        test = [ordered]@{
            suffix = $uniqueSuffix
            created_by_api_test = $true
        }
    }

    $stateJson = $stateObject |
        ConvertTo-Json -Depth 20

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
        throw "No fue posible crear assessments.json."
    }

    Write-Host "   OK"
    Write-Host "   Estado: $stateFile"
    Write-Host ""

    Write-Host "9. Verificando archivo de estado..."

    $savedStateJson = [System.IO.File]::ReadAllText(
        $stateFile
    )

    $savedState = $savedStateJson |
        ConvertFrom-Json

    if (
        [string]$savedState.assessment.id `
            -ne $assessmentId
    ) {
        throw "El Assessment ID guardado no coincide."
    }

    if (
        [string]$savedState.dependencies.teaching_assignment.id `
            -ne $teachingAssignmentId
    ) {
        throw "El Teaching Assignment ID guardado no coincide."
    }

    if (
        [string]$savedState.dependencies.grading_period.id `
            -ne $gradingPeriodId
    ) {
        throw "El Grading Period ID guardado no coincide."
    }

    if (
        $savedState.dependencies.teaching_assignment.created_by_api_test `
            -ne $false
    ) {
        throw "La asignacion existente esta marcada incorrectamente."
    }

    if (
        $savedState.dependencies.grading_period.created_by_api_test `
            -ne $false
    ) {
        throw "El periodo existente esta marcado incorrectamente."
    }

    if (
        $savedState.test.created_by_api_test `
            -ne $true
    ) {
        throw "La evaluacion no esta marcada como temporal."
    }

    Write-Host "   OK"
    Write-Host ""

    $creationSucceeded = $true
}
catch {
    Write-Host ""
    Write-Host `
        "FALLO LA CREACION DE DATOS DE ASSESSMENTS." `
        -ForegroundColor Red

    Write-Host (
        Get-ErrorResponseBody `
            -ErrorRecord $_
    ) -ForegroundColor Red
}
finally {
    if (-not $creationSucceeded) {
        if ($assessmentId) {
            Write-Host ""
            Write-Host `
                "Intentando eliminar la evaluacion incompleta..." `
                -ForegroundColor Yellow

            try {
                Invoke-RestMethod `
                    -Uri "$backendUrl/api/assessments/$assessmentId" `
                    -Method Delete `
                    -WebSession $global:webSession `
                    -Headers $campusHeaders |
                    Out-Null

                Write-Host `
                    "Evaluacion temporal eliminada." `
                    -ForegroundColor Yellow
            }
            catch {
                Write-Host `
                    "No fue posible eliminar la evaluacion temporal." `
                    -ForegroundColor Red

                Write-Host `
                    "Assessment ID: $assessmentId" `
                    -ForegroundColor Red
            }
        }

        if (Test-Path -LiteralPath $stateFile) {
            Remove-Item `
                -LiteralPath $stateFile `
                -Force
        }
    }
}

if (-not $creationSucceeded) {
    throw "No fue posible preparar los datos de Assessments."
}

Write-Host "========================================"
Write-Host " RESULTADO: CREATE ASSESSMENTS OK" `
    -ForegroundColor Green
Write-Host "========================================"
Write-Host ""
Write-Host "Campus ID:                    $campusId"
Write-Host "Teaching Assignment existente: $teachingAssignmentId"
Write-Host "Grading Period existente:      $gradingPeriodId"
Write-Host "Assessment ID temporal:        $assessmentId"
Write-Host "Consulta GET:                  OK"
Write-Host "Archivo de estado:             OK"
Write-Host ""
Write-Host "La evaluacion NO fue eliminada."
Write-Host "La asignacion docente NO fue modificada."
Write-Host "El periodo de calificacion NO fue modificado."
Write-Host ""
Write-Host "Siguiente fase: Test-AssessmentsApi.ps1"
Write-Host ""