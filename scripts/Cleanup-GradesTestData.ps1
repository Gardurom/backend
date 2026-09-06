$ErrorActionPreference = "Stop"

$backendUrl = "http://127.0.0.1:8000"

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$backendRoot = Split-Path -Parent $scriptRoot

$stateFile = Join-Path $scriptRoot ".test-state\grades.json"

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

function Assert-Equal {
    param(
        [Parameter(Mandatory = $true)]
        $Actual,

        [Parameter(Mandatory = $true)]
        $Expected,

        [Parameter(Mandatory = $true)]
        [string]$Message
    )

    if ([string]$Actual -ne [string]$Expected) {
        throw @"
$Message

Esperado:
$Expected

Recibido:
$Actual
"@
    }
}

function Invoke-ExpectedHttpStatus {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Uri,

        [Parameter(Mandatory = $true)]
        [string]$Method,

        [Parameter(Mandatory = $true)]
        [Microsoft.PowerShell.Commands.WebRequestSession]
        $WebSession,

        [Parameter(Mandatory = $true)]
        [hashtable]$Headers,

        [Parameter(Mandatory = $true)]
        [int]$ExpectedStatus
    )

    try {
        $response = Invoke-WebRequest `
            -Uri $Uri `
            -Method $Method `
            -WebSession $WebSession `
            -Headers $Headers `
            -UseBasicParsing `
            -ErrorAction Stop

        $actualStatus = [int]$response.StatusCode

        if ($actualStatus -ne $ExpectedStatus) {
            throw @"
Se esperaba HTTP $ExpectedStatus,
pero se recibio HTTP $actualStatus.
"@
        }

        return $actualStatus
    }
    catch {
        $response = $_.Exception.Response

        if ($null -eq $response) {
            throw
        }

        $actualStatus = [int]$response.StatusCode

        if ($actualStatus -ne $ExpectedStatus) {
            $body = Get-ErrorResponseBody -ErrorRecord $_

            throw @"
Se esperaba HTTP $ExpectedStatus,
pero se recibio HTTP $actualStatus.

Respuesta:
$body
"@
        }

        return $actualStatus
    }
}

function Remove-ExactTemporaryGrade {
    param(
        [Parameter(Mandatory = $true)]
        [string]$GradeId,

        [Parameter(Mandatory = $true)]
        [string]$AssessmentId,

        [Parameter(Mandatory = $true)]
        [string]$EnrollmentId
    )

    $phpCode = @"
`$grade = App\Models\Grade::query()->find('$GradeId');

if (! `$grade) {
    echo '__GRADE_ALREADY_ABSENT__';
    return;
}

if (
    (string) `$grade->assessment_id !== '$AssessmentId'
    || (string) `$grade->enrollment_id !== '$EnrollmentId'
) {
    echo '__GRADE_IDENTITY_MISMATCH__';
    return;
}

`$grade->delete();

`$exists = App\Models\Grade::query()->where('id', '$GradeId')->exists();

echo `$exists
    ? '__GRADE_DELETE_FAILED__'
    : '__GRADE_DELETE_OK__';
"@

    Push-Location $backendRoot

    try {
        $output = & php artisan tinker `
            --execute="$phpCode" 2>&1
    }
    finally {
        Pop-Location
    }

    $text = $output -join "`n"

    if ($text -match "__GRADE_IDENTITY_MISMATCH__") {
        throw @"
La Grade encontrada por UUID no coincide con
Assessment/Enrollment del estado.

No se elimino ningun registro.
"@
    }

    if ($text -match "__GRADE_DELETE_FAILED__") {
        throw "La Grade temporal no pudo eliminarse."
    }

    if (
        $text -notmatch "__GRADE_DELETE_OK__" `
        -and $text -notmatch "__GRADE_ALREADY_ABSENT__"
    ) {
        throw @"
No fue posible confirmar el cleanup de la Grade.

Salida de Tinker:

$text
"@
    }

    return $text
}

Write-Host ""
Write-Host "========================================"
Write-Host " CONTROL ESCOLAR - CLEANUP API"
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

if (-not (Test-Path -LiteralPath $stateFile)) {
    Write-Host "No existe estado pendiente de Grades."
    Write-Host "Cleanup no requerido."
    Write-Host ""
    Write-Host "========================================"
    Write-Host " RESULTADO: CLEANUP GRADES OK" `
        -ForegroundColor Green
    Write-Host "========================================"
    exit 0
}

try {
    Write-Host "1. Cargando y validando estado..."

    $state = Get-Content `
        -LiteralPath $stateFile `
        -Raw |
        ConvertFrom-Json

    Assert-Equal `
        -Actual $state.module `
        -Expected "grades" `
        -Message "El estado no pertenece al modulo Grades."

    Assert-Equal `
        -Actual $state.test.created_by_api_test `
        -Expected $true `
        -Message "El estado no esta marcado como fixture de prueba."

    foreach (
        $ownership in @(
            $state.subject.created_by_api_test,
            $state.teaching_assignment.created_by_api_test,
            $state.assessment.created_by_api_test,
            $state.grade.created_by_api_test
        )
    ) {
        Assert-Equal `
            -Actual $ownership `
            -Expected $true `
            -Message "Uno de los fixtures no esta marcado como temporal."
    }

    Assert-Equal `
        -Actual $state.campus.created_by_api_test `
        -Expected $false `
        -Message "El campus no debe ser temporal."

    Assert-Equal `
        -Actual $state.dependencies.school_group.created_by_api_test `
        -Expected $false `
        -Message "El grupo no debe ser temporal."

    Assert-Equal `
        -Actual $state.dependencies.teacher.created_by_api_test `
        -Expected $false `
        -Message "El profesor no debe ser temporal."

    Assert-Equal `
        -Actual $state.dependencies.grading_period.created_by_api_test `
        -Expected $false `
        -Message "El periodo no debe ser temporal."

    Assert-Equal `
        -Actual $state.dependencies.enrollment.created_by_api_test `
        -Expected $false `
        -Message "La inscripcion no debe ser temporal."

    $campusId = [string]$state.campus.id

    $schoolGroupId = [string](
        $state.dependencies.school_group.id
    )

    $teacherId = [string](
        $state.dependencies.teacher.id
    )

    $gradingPeriodId = [string](
        $state.dependencies.grading_period.id
    )

    $enrollmentId = [string](
        $state.dependencies.enrollment.id
    )

    $studentId = [string](
        $state.dependencies.enrollment.student_id
    )

    $subjectId = [string]$state.subject.id
    $subjectCode = [string]$state.subject.code

    $teachingAssignmentId = [string](
        $state.teaching_assignment.id
    )

    $assessmentId = [string]$state.assessment.id
    $assessmentName = [string]$state.assessment.name

    $gradeId = [string]$state.grade.id

    foreach (
        $requiredValue in @(
            $campusId,
            $schoolGroupId,
            $teacherId,
            $gradingPeriodId,
            $enrollmentId,
            $studentId,
            $subjectId,
            $subjectCode,
            $teachingAssignmentId,
            $assessmentId,
            $assessmentName,
            $gradeId
        )
    ) {
        if (
            [string]::IsNullOrWhiteSpace(
                [string]$requiredValue
            )
        ) {
            throw "El archivo de estado contiene datos incompletos."
        }
    }

    Write-Host "   OK"
    Write-Host "   Grade:               $gradeId"
    Write-Host "   Assessment:          $assessmentId"
    Write-Host "   Teaching Assignment: $teachingAssignmentId"
    Write-Host "   Subject:             $subjectId"
    Write-Host ""

    $campusHeaders = $global:authHeaders.Clone()
    $campusHeaders["Accept"] = "application/json"
    $campusHeaders["X-Campus-ID"] = $campusId

    Write-Host "2. Verificando sesion autenticada..."

    $currentUser = Invoke-RestMethod `
        -Uri "$backendUrl/api/auth/me" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $global:authHeaders

    if (-not $currentUser.user) {
        throw "La sesion autenticada ya no es valida."
    }

    Write-Host "   OK"
    Write-Host ""

    Write-Host "3. Verificando identidad exacta de Grade..."

    try {
        $gradeResponse = Invoke-RestMethod `
            -Uri "$backendUrl/api/grades/$gradeId" `
            -Method Get `
            -WebSession $global:webSession `
            -Headers $campusHeaders

        Assert-Equal `
            -Actual $gradeResponse.data.id `
            -Expected $gradeId `
            -Message "La API devolvio otra Grade."

        Assert-Equal `
            -Actual $gradeResponse.data.assessment_id `
            -Expected $assessmentId `
            -Message "La Grade no pertenece al Assessment temporal."

        Assert-Equal `
            -Actual $gradeResponse.data.enrollment_id `
            -Expected $enrollmentId `
            -Message "La Grade no pertenece al Enrollment esperado."

        Assert-Equal `
            -Actual $gradeResponse.data.enrollment.student.id `
            -Expected $studentId `
            -Message "La Grade pertenece a otro Student."

        Write-Host "   OK"
    }
    catch {
        $status = $null

        if ($_.Exception.Response) {
            $status = [int]$_.Exception.Response.StatusCode
        }

        if ($status -eq 404) {
            Write-Host "   Grade ya ausente; se continuara de forma idempotente."
        }
        else {
            throw
        }
    }

    Write-Host ""

    Write-Host "4. Verificando identidad exacta de Assessment..."

    $assessmentResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/assessments/$assessmentId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $campusHeaders

    Assert-Equal `
        -Actual $assessmentResponse.data.id `
        -Expected $assessmentId `
        -Message "La API devolvio otra Assessment."

    Assert-Equal `
        -Actual $assessmentResponse.data.name `
        -Expected $assessmentName `
        -Message "La Assessment no coincide con el estado."

    Assert-Equal `
        -Actual $assessmentResponse.data.teaching_assignment.id `
        -Expected $teachingAssignmentId `
        -Message "La Assessment pertenece a otra TeachingAssignment."

    Assert-Equal `
        -Actual $assessmentResponse.data.grading_period.id `
        -Expected $gradingPeriodId `
        -Message "La Assessment pertenece a otro periodo."

    Write-Host "   OK"
    Write-Host ""

    Write-Host "5. Verificando identidad exacta de TeachingAssignment..."

    $assignmentResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/teaching-assignments/$teachingAssignmentId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $campusHeaders

    Assert-Equal `
        -Actual $assignmentResponse.data.id `
        -Expected $teachingAssignmentId `
        -Message "La API devolvio otra TeachingAssignment."

    Assert-Equal `
        -Actual $assignmentResponse.data.school_group_id `
        -Expected $schoolGroupId `
        -Message "La TeachingAssignment pertenece a otro grupo."

    Assert-Equal `
        -Actual $assignmentResponse.data.subject_id `
        -Expected $subjectId `
        -Message "La TeachingAssignment pertenece a otro Subject."

    Assert-Equal `
        -Actual $assignmentResponse.data.teacher_id `
        -Expected $teacherId `
        -Message "La TeachingAssignment pertenece a otro Teacher."

    Write-Host "   OK"
    Write-Host ""

    Write-Host "6. Verificando identidad exacta de Subject..."

    $subjectResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/subjects/$subjectId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $campusHeaders

    Assert-Equal `
        -Actual $subjectResponse.data.id `
        -Expected $subjectId `
        -Message "La API devolvio otro Subject."

    Assert-Equal `
        -Actual $subjectResponse.data.code `
        -Expected $subjectCode `
        -Message "El Subject no coincide con el codigo de prueba."

    Write-Host "   OK"
    Write-Host ""

    Write-Host "7. Eliminando exclusivamente Grade temporal..."

    $gradeCleanupOutput = Remove-ExactTemporaryGrade `
        -GradeId $gradeId `
        -AssessmentId $assessmentId `
        -EnrollmentId $enrollmentId

    if ($gradeCleanupOutput -match "__GRADE_ALREADY_ABSENT__") {
        Write-Host "   Grade ya estaba ausente."
    }
    else {
        Write-Host "   Grade eliminada."
    }

    Write-Host ""

    Write-Host "8. Confirmando ausencia de Grade..."

    $grade404 = Invoke-ExpectedHttpStatus `
        -Uri "$backendUrl/api/grades/$gradeId" `
        -Method "Get" `
        -WebSession $global:webSession `
        -Headers $campusHeaders `
        -ExpectedStatus 404

    Assert-Equal `
        -Actual $grade404 `
        -Expected 404 `
        -Message "La Grade temporal sigue disponible."

    Write-Host "   OK"
    Write-Host "   HTTP: 404"
    Write-Host ""

    Write-Host "9. Eliminando Assessment temporal por API..."

    Invoke-RestMethod `
        -Uri "$backendUrl/api/assessments/$assessmentId" `
        -Method Delete `
        -WebSession $global:webSession `
        -Headers $campusHeaders |
        Out-Null

    Write-Host "   OK"
    Write-Host ""

    Write-Host "10. Confirmando ausencia de Assessment..."

    $assessment404 = Invoke-ExpectedHttpStatus `
        -Uri "$backendUrl/api/assessments/$assessmentId" `
        -Method "Get" `
        -WebSession $global:webSession `
        -Headers $campusHeaders `
        -ExpectedStatus 404

    Assert-Equal `
        -Actual $assessment404 `
        -Expected 404 `
        -Message "La Assessment temporal sigue disponible."

    Write-Host "   OK"
    Write-Host "   HTTP: 404"
    Write-Host ""

    Write-Host "11. Eliminando TeachingAssignment temporal por API..."

    Invoke-RestMethod `
        -Uri "$backendUrl/api/teaching-assignments/$teachingAssignmentId" `
        -Method Delete `
        -WebSession $global:webSession `
        -Headers $campusHeaders |
        Out-Null

    Write-Host "   OK"
    Write-Host ""

    Write-Host "12. Confirmando ausencia de TeachingAssignment..."

    $assignment404 = Invoke-ExpectedHttpStatus `
        -Uri "$backendUrl/api/teaching-assignments/$teachingAssignmentId" `
        -Method "Get" `
        -WebSession $global:webSession `
        -Headers $campusHeaders `
        -ExpectedStatus 404

    Assert-Equal `
        -Actual $assignment404 `
        -Expected 404 `
        -Message "La TeachingAssignment temporal sigue disponible."

    Write-Host "   OK"
    Write-Host "   HTTP: 404"
    Write-Host ""

    Write-Host "13. Eliminando Subject temporal por API..."

    Invoke-RestMethod `
        -Uri "$backendUrl/api/subjects/$subjectId" `
        -Method Delete `
        -WebSession $global:webSession `
        -Headers $campusHeaders |
        Out-Null

    Write-Host "   OK"
    Write-Host ""

    Write-Host "14. Confirmando ausencia de Subject..."

    $subject404 = Invoke-ExpectedHttpStatus `
        -Uri "$backendUrl/api/subjects/$subjectId" `
        -Method "Get" `
        -WebSession $global:webSession `
        -Headers $campusHeaders `
        -ExpectedStatus 404

    Assert-Equal `
        -Actual $subject404 `
        -Expected 404 `
        -Message "El Subject temporal sigue disponible."

    Write-Host "   OK"
    Write-Host "   HTTP: 404"
    Write-Host ""

    Write-Host "15. Confirmando dependencias existentes preservadas..."

    $groupResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/groups/$schoolGroupId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $campusHeaders

    Assert-Equal `
        -Actual $groupResponse.data.id `
        -Expected $schoolGroupId `
        -Message "El SchoolGroup existente fue alterado o eliminado."

    $teacherResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/teachers/$teacherId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $campusHeaders

    Assert-Equal `
        -Actual $teacherResponse.data.id `
        -Expected $teacherId `
        -Message "El Teacher existente fue alterado o eliminado."

    Write-Host "   OK"
    Write-Host "   SchoolGroup preservado."
    Write-Host "   Teacher preservado."
    Write-Host "   Enrollment/Periodo no fueron eliminados."
    Write-Host ""

    Write-Host "16. Eliminando archivo de estado..."

    Remove-Item `
        -LiteralPath $stateFile `
        -Force

    if (Test-Path -LiteralPath $stateFile) {
        throw "No fue posible eliminar grades.json."
    }

    Write-Host "   OK"
    Write-Host ""

    Write-Host "========================================"
    Write-Host " RESULTADO: CLEANUP GRADES OK" `
        -ForegroundColor Green
    Write-Host "========================================"
    Write-Host ""

    Write-Host "Grade temporal eliminada:"
    Write-Host "  $gradeId"
    Write-Host ""

    Write-Host "Assessment temporal eliminada:"
    Write-Host "  $assessmentId"
    Write-Host ""

    Write-Host "Teaching Assignment temporal eliminada:"
    Write-Host "  $teachingAssignmentId"
    Write-Host ""

    Write-Host "Subject temporal eliminado:"
    Write-Host "  $subjectId"
    Write-Host ""

    Write-Host "Estado eliminado:"
    Write-Host "  $stateFile"
    Write-Host ""
}
catch {
    Write-Host ""
    Write-Host "CLEANUP GRADES FALLO" `
        -ForegroundColor Red

    Write-Host (
        Get-ErrorResponseBody `
            -ErrorRecord $_
    ) -ForegroundColor Red

    Write-Host ""
    Write-Host (
        "El archivo de estado NO se elimina si " +
        "el cleanup no termina correctamente."
    ) -ForegroundColor Yellow

    throw
}
