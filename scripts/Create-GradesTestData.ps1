$ErrorActionPreference = "Stop"

$backendUrl = "http://127.0.0.1:8000"

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$backendRoot = Split-Path -Parent $scriptRoot

$stateDirectory = Join-Path $scriptRoot ".test-state"
$stateFile = Join-Path $stateDirectory "grades.json"

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

        if ($null -eq $stream) {
            return $ErrorRecord.Exception.Message
        }

        $reader = New-Object System.IO.StreamReader($stream)
        $body = $reader.ReadToEnd()
        $reader.Dispose()

        return $body
    }
    catch {
        return $ErrorRecord.Exception.Message
    }
}

function Remove-TemporaryGrade {
    param(
        [Parameter(Mandatory = $true)]
        [string]$GradeId
    )

    $phpCode = (
        "App\Models\Grade::query()" +
        "->where('id', '$GradeId')" +
        "->delete();" +
        " echo 'GRADE_CLEANUP_OK';"
    )

    Push-Location $backendRoot

    try {
        $output = & php artisan tinker `
            --execute="$phpCode" 2>&1

        $outputText = $output -join "`n"

        if ($outputText -notmatch "GRADE_CLEANUP_OK") {
            throw "No fue posible confirmar cleanup de Grade.`n$outputText"
        }
    }
    finally {
        Pop-Location
    }
}

Write-Host ""
Write-Host "========================================"
Write-Host " CONTROL ESCOLAR - PREPARACION API"
Write-Host " MODULO: CALIFICACIONES"
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
Ya existe estado pendiente para Grades:

$stateFile
"@
}

$temporarySubjectId = $null
$temporaryAssignmentId = $null
$temporaryAssessmentId = $null
$temporaryGradeId = $null

$campusId = $null
$campusHeaders = $null

try {
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

    Write-Host "2. Localizando grupo, profesor, periodo e inscripcion compatibles..."

    $probeCode = @'
$row = DB::table('teaching_assignments as ta')
    ->join('school_groups as sg', 'sg.id', '=', 'ta.school_group_id')
    ->join('school_cycles as sc', 'sc.id', '=', 'sg.school_cycle_id')
    ->join('teachers as t', 't.id', '=', 'ta.teacher_id')
    ->join('enrollments as e', function ($join) {
        $join
            ->on('e.school_group_id', '=', 'sg.id')
            ->on('e.school_cycle_id', '=', 'sc.id');
    })
    ->join('students as s', 's.id', '=', 'e.student_id')
    ->join('grading_periods as gp', 'gp.school_cycle_id', '=', 'sc.id')
    ->whereNull('ta.deleted_at')
    ->whereNull('sg.deleted_at')
    ->whereNull('t.deleted_at')
    ->whereNull('e.deleted_at')
    ->whereNull('s.deleted_at')
    ->where('sg.is_active', true)
    ->where('t.status', 'active')
    ->whereIn('e.status', ['active', 'completed'])
    ->orderBy('ta.created_at')
    ->orderBy('e.created_at')
    ->orderBy('gp.created_at')
    ->select([
        'sc.campus_id',
        'sc.id as school_cycle_id',
        'sc.starts_on as cycle_starts_on',
        'sc.ends_on as cycle_ends_on',
        'sg.id as school_group_id',
        'ta.id as source_teaching_assignment_id',
        'ta.teacher_id',
        'gp.id as grading_period_id',
        'gp.name as grading_period_name',
        'e.id as enrollment_id',
        'e.student_id',
        's.enrollment_number',
    ])
    ->first();

if (! $row) {
    echo '__GRADES_PROBE_NOT_FOUND__';
    return;
}

echo '__GRADES_PROBE__'
    . json_encode(
        $row,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    )
    . '__END_GRADES_PROBE__';
'@

    Push-Location $backendRoot

    try {
        $probeOutput = & php artisan tinker `
            --execute="$probeCode" 2>&1
    }
    finally {
        Pop-Location
    }

    $probeText = $probeOutput -join "`n"

    if ($probeText -match "__GRADES_PROBE_NOT_FOUND__") {
        throw "No existe una combinacion academica compatible."
    }

    $probeMatch = [regex]::Match(
        $probeText,
        "__GRADES_PROBE__(.*?)__END_GRADES_PROBE__",
        [System.Text.RegularExpressions.RegexOptions]::Singleline
    )

    if (-not $probeMatch.Success) {
        throw "No fue posible interpretar dependencias.`n$probeText"
    }

    $dependencies = $probeMatch.Groups[1].Value.Trim() |
        ConvertFrom-Json

    $campusId = [string]$dependencies.campus_id
    $schoolCycleId = [string]$dependencies.school_cycle_id
    $cycleStartsOn = [string]$dependencies.cycle_starts_on
    $cycleEndsOn = [string]$dependencies.cycle_ends_on
    $schoolGroupId = [string]$dependencies.school_group_id
    $sourceTeachingAssignmentId = [string]$dependencies.source_teaching_assignment_id
    $teacherId = [string]$dependencies.teacher_id
    $gradingPeriodId = [string]$dependencies.grading_period_id
    $gradingPeriodName = [string]$dependencies.grading_period_name
    $enrollmentId = [string]$dependencies.enrollment_id
    $studentId = [string]$dependencies.student_id
    $enrollmentNumber = [string]$dependencies.enrollment_number

    Write-Host "   OK"
    Write-Host "   Campus ID:              $campusId"
    Write-Host "   School Cycle ID:        $schoolCycleId"
    Write-Host "   School Group ID:        $schoolGroupId"
    Write-Host "   Profesor existente:     $teacherId"
    Write-Host "   Grading Period ID:      $gradingPeriodId"
    Write-Host "   Enrollment ID:          $enrollmentId"
    Write-Host "   Student ID:             $studentId"
    Write-Host "   Enrollment number:      $enrollmentNumber"
    Write-Host ""

    $campusHeaders = $global:authHeaders.Clone()
    $campusHeaders["Accept"] = "application/json"
    $campusHeaders["X-Campus-ID"] = $campusId

    Write-Host "3. Verificando grupo existente..."

    $groupResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/groups/$schoolGroupId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $campusHeaders

    if ([string]$groupResponse.data.id -ne $schoolGroupId) {
        throw "La API devolvio un grupo diferente."
    }

    Write-Host "   OK"
    Write-Host ""

    Write-Host "4. Verificando profesor existente..."

    $teacherResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/teachers/$teacherId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $campusHeaders

    if ([string]$teacherResponse.data.id -ne $teacherId) {
        throw "La API devolvio un profesor diferente."
    }

    Write-Host "   OK"
    Write-Host ""

    $uniqueSuffix = Get-Date -Format "yyyyMMddHHmmssfff"

    $subjectCode = "TEST-GRADE-$uniqueSuffix"
    $subjectName = "Materia Grades API $uniqueSuffix"

    Write-Host "5. Creando materia temporal..."

    $subjectBody = @{
        campus_id = $campusId
        code = $subjectCode
        name = $subjectName
        description = "Materia temporal para pruebas de Grades."
        weekly_hours = 1
        is_active = $true
    } | ConvertTo-Json -Depth 10

    $subjectResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/subjects" `
        -Method Post `
        -WebSession $global:webSession `
        -Headers $campusHeaders `
        -ContentType "application/json; charset=utf-8" `
        -Body ([System.Text.Encoding]::UTF8.GetBytes($subjectBody))

    $temporarySubjectId = [string]$subjectResponse.data.id

    if ([string]::IsNullOrWhiteSpace($temporarySubjectId)) {
        throw "La materia temporal no contiene UUID."
    }

    Write-Host "   OK"
    Write-Host "   Subject ID: $temporarySubjectId"
    Write-Host ""

    Write-Host "6. Creando asignacion docente temporal..."

    $assignmentStartsOn = ([DateTime]$cycleStartsOn).ToString("yyyy-MM-dd")
    $assignmentEndsOn = ([DateTime]$cycleEndsOn).ToString("yyyy-MM-dd")

    $assignmentBody = @{
        school_group_id = $schoolGroupId
        subject_id = $temporarySubjectId
        teacher_id = $teacherId
        starts_on = $assignmentStartsOn
        ends_on = $assignmentEndsOn
        status = "active"
    } | ConvertTo-Json -Depth 10

    $assignmentResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/teaching-assignments" `
        -Method Post `
        -WebSession $global:webSession `
        -Headers $campusHeaders `
        -ContentType "application/json; charset=utf-8" `
        -Body ([System.Text.Encoding]::UTF8.GetBytes($assignmentBody))

    $temporaryAssignmentId = [string]$assignmentResponse.data.id

    if ([string]::IsNullOrWhiteSpace($temporaryAssignmentId)) {
        throw "La asignacion temporal no contiene UUID."
    }

    Write-Host "   OK"
    Write-Host "   Teaching Assignment ID: $temporaryAssignmentId"
    Write-Host ""

    Write-Host "7. Verificando ponderacion inicial..."

    $weightCode = (
        "`$weight = App\Models\Assessment::query()" +
        "->where('teaching_assignment_id', '$temporaryAssignmentId')" +
        "->where('grading_period_id', '$gradingPeriodId')" +
        "->where('status', '<>', 'cancelled')" +
        "->sum('weight');" +
        " echo '__WEIGHT__' . (float) `$weight . '__END_WEIGHT__';"
    )

    Push-Location $backendRoot

    try {
        $weightOutput = & php artisan tinker `
            --execute="$weightCode" 2>&1
    }
    finally {
        Pop-Location
    }

    $weightText = $weightOutput -join "`n"

    $weightMatch = [regex]::Match(
        $weightText,
        "__WEIGHT__(.*?)__END_WEIGHT__",
        [System.Text.RegularExpressions.RegexOptions]::Singleline
    )

    if (-not $weightMatch.Success) {
        throw "No fue posible leer la ponderacion inicial.`n$weightText"
    }

    [decimal]$existingWeight = $weightMatch.Groups[1].Value.Trim()

    if ($existingWeight -ne 0) {
        throw "La asignacion temporal ya contiene ponderacion inesperada."
    }

    Write-Host "   OK"
    Write-Host "   Ponderacion utilizada: 0"
    Write-Host "   Ponderacion disponible: 100"
    Write-Host ""

    $assessmentName = "Evaluacion Grades API $uniqueSuffix"

    Write-Host "8. Creando evaluacion temporal..."

    $assessmentBody = @{
        teaching_assignment_id = $temporaryAssignmentId
        grading_period_id = $gradingPeriodId
        name = $assessmentName
        description = "Evaluacion temporal para pruebas de Grades."
        type = "quiz"
        maximum_score = 100
        weight = 10
        due_at = (Get-Date).AddDays(7).ToUniversalTime().ToString(
            "yyyy-MM-ddTHH:mm:ss.fffZ"
        )
        status = "published"
    } | ConvertTo-Json -Depth 10

    $assessmentResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/assessments" `
        -Method Post `
        -WebSession $global:webSession `
        -Headers $campusHeaders `
        -ContentType "application/json; charset=utf-8" `
        -Body ([System.Text.Encoding]::UTF8.GetBytes($assessmentBody))

    $temporaryAssessmentId = [string]$assessmentResponse.data.id

    if ([string]::IsNullOrWhiteSpace($temporaryAssessmentId)) {
        throw "La evaluacion temporal no contiene UUID."
    }

    Write-Host "   OK"
    Write-Host "   Assessment ID: $temporaryAssessmentId"
    Write-Host ""

    Write-Host "9. Confirmando evaluacion mediante GET..."

    $assessmentGet = Invoke-RestMethod `
        -Uri "$backendUrl/api/assessments/$temporaryAssessmentId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $campusHeaders

    if ([string]$assessmentGet.data.id -ne $temporaryAssessmentId) {
        throw "El GET devolvio otra evaluacion."
    }

    Write-Host "   OK"
    Write-Host ""

    $initialScore = 80
    $initialFeedback = "Calificacion temporal inicial $uniqueSuffix"

    Write-Host "10. Creando calificacion temporal mediante API..."

    $gradeBody = @{
        assessment_id = $temporaryAssessmentId
        enrollment_id = $enrollmentId
        score = $initialScore
        feedback = $initialFeedback
        status = "graded"
    } | ConvertTo-Json -Depth 10

    $gradeResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/grades" `
        -Method Post `
        -WebSession $global:webSession `
        -Headers $campusHeaders `
        -ContentType "application/json; charset=utf-8" `
        -Body ([System.Text.Encoding]::UTF8.GetBytes($gradeBody))

    $temporaryGradeId = [string]$gradeResponse.data.id

    if ([string]::IsNullOrWhiteSpace($temporaryGradeId)) {
        throw "La Grade temporal no contiene UUID."
    }

    if ([string]$gradeResponse.data.assessment_id -ne $temporaryAssessmentId) {
        throw "Assessment ID incorrecto en Grade."
    }

    if ([string]$gradeResponse.data.enrollment_id -ne $enrollmentId) {
        throw "Enrollment ID incorrecto en Grade."
    }

    Write-Host "   OK"
    Write-Host "   Grade ID: $temporaryGradeId"
    Write-Host ""

    Write-Host "11. Confirmando calificacion mediante GET..."

    $gradeGet = Invoke-RestMethod `
        -Uri "$backendUrl/api/grades/$temporaryGradeId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $campusHeaders

    if ([string]$gradeGet.data.id -ne $temporaryGradeId) {
        throw "El GET devolvio otra Grade."
    }

    Write-Host "   OK"
    Write-Host ""

    Write-Host "12. Guardando estado de prueba..."

    if (-not (Test-Path -LiteralPath $stateDirectory)) {
        New-Item `
            -ItemType Directory `
            -Path $stateDirectory `
            -Force |
            Out-Null
    }

    $state = [ordered]@{
        module = "grades"

        test = [ordered]@{
            created_by_api_test = $true
            suffix = $uniqueSuffix
            created_at = (Get-Date).ToUniversalTime().ToString(
                "yyyy-MM-ddTHH:mm:ss.fffZ"
            )
        }

        campus = [ordered]@{
            id = $campusId
            created_by_api_test = $false
        }

        dependencies = [ordered]@{
            school_cycle = [ordered]@{
                id = $schoolCycleId
                created_by_api_test = $false
            }

            school_group = [ordered]@{
                id = $schoolGroupId
                created_by_api_test = $false
            }

            source_teaching_assignment = [ordered]@{
                id = $sourceTeachingAssignmentId
                created_by_api_test = $false
            }

            teacher = [ordered]@{
                id = $teacherId
                created_by_api_test = $false
            }

            grading_period = [ordered]@{
                id = $gradingPeriodId
                name = $gradingPeriodName
                created_by_api_test = $false
            }

            enrollment = [ordered]@{
                id = $enrollmentId
                student_id = $studentId
                enrollment_number = $enrollmentNumber
                created_by_api_test = $false
            }
        }

        subject = [ordered]@{
            id = $temporarySubjectId
            code = $subjectCode
            name = $subjectName
            created_by_api_test = $true
        }

        teaching_assignment = [ordered]@{
            id = $temporaryAssignmentId
            school_group_id = $schoolGroupId
            subject_id = $temporarySubjectId
            teacher_id = $teacherId
            created_by_api_test = $true
        }

        assessment = [ordered]@{
            id = $temporaryAssessmentId
            teaching_assignment_id = $temporaryAssignmentId
            grading_period_id = $gradingPeriodId
            name = $assessmentName
            maximum_score = 100
            weight = 10
            created_by_api_test = $true
        }

        grade = [ordered]@{
            id = $temporaryGradeId
            assessment_id = $temporaryAssessmentId
            enrollment_id = $enrollmentId
            student_id = $studentId
            initial_score = $initialScore
            initial_feedback = $initialFeedback
            initial_status = "graded"
            created_by_api_test = $true
        }
    }

    $stateJson = $state | ConvertTo-Json -Depth 20

    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)

    [System.IO.File]::WriteAllText(
        $stateFile,
        $stateJson,
        $utf8NoBom
    )

    Write-Host "   OK"
    Write-Host "   $stateFile"
    Write-Host ""

    Write-Host "========================================"
    Write-Host " RESULTADO: CREATE GRADES OK" `
        -ForegroundColor Green
    Write-Host "========================================"
    Write-Host ""

    Write-Host "Subject temporal:"
    Write-Host "  $temporarySubjectId"

    Write-Host "Teaching Assignment temporal:"
    Write-Host "  $temporaryAssignmentId"

    Write-Host "Assessment temporal:"
    Write-Host "  $temporaryAssessmentId"

    Write-Host "Grade temporal:"
    Write-Host "  $temporaryGradeId"
}
catch {
    $originalError = $_

    Write-Host ""
    Write-Host "CREATE GRADES FALLO" `
        -ForegroundColor Red

    Write-Host (
        Get-ErrorResponseBody `
            -ErrorRecord $originalError
    ) -ForegroundColor Red

    if ($temporaryGradeId) {
        try {
            Remove-TemporaryGrade -GradeId $temporaryGradeId
        }
        catch {
        }
    }

    if ($temporaryAssessmentId -and $campusHeaders) {
        try {
            Invoke-RestMethod `
                -Uri "$backendUrl/api/assessments/$temporaryAssessmentId" `
                -Method Delete `
                -WebSession $global:webSession `
                -Headers $campusHeaders |
                Out-Null
        }
        catch {
        }
    }

    if ($temporaryAssignmentId -and $campusHeaders) {
        try {
            Invoke-RestMethod `
                -Uri "$backendUrl/api/teaching-assignments/$temporaryAssignmentId" `
                -Method Delete `
                -WebSession $global:webSession `
                -Headers $campusHeaders |
                Out-Null
        }
        catch {
        }
    }

    if ($temporarySubjectId -and $campusHeaders) {
        try {
            Invoke-RestMethod `
                -Uri "$backendUrl/api/subjects/$temporarySubjectId" `
                -Method Delete `
                -WebSession $global:webSession `
                -Headers $campusHeaders |
                Out-Null
        }
        catch {
        }
    }

    if (Test-Path -LiteralPath $stateFile) {
        Remove-Item `
            -LiteralPath $stateFile `
            -Force `
            -ErrorAction SilentlyContinue
    }

    throw $originalError
}
