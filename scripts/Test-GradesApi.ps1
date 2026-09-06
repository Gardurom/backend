$ErrorActionPreference = "Stop"

$backendUrl = "http://127.0.0.1:8000"

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
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

function Assert-CollectionContainsId {
    param(
        [Parameter(Mandatory = $true)]
        $Response,

        [Parameter(Mandatory = $true)]
        [string]$ExpectedId,

        [Parameter(Mandatory = $true)]
        [string]$Message
    )

    $items = @($Response.data)

    $match = @(
        $items |
            Where-Object {
                [string]$_.id -eq $ExpectedId
            }
    )

    if ($match.Count -lt 1) {
        throw $Message
    }
}

function Assert-CollectionDoesNotContainId {
    param(
        [Parameter(Mandatory = $true)]
        $Response,

        [Parameter(Mandatory = $true)]
        [string]$UnexpectedId,

        [Parameter(Mandatory = $true)]
        [string]$Message
    )

    $items = @($Response.data)

    $match = @(
        $items |
            Where-Object {
                [string]$_.id -eq $UnexpectedId
            }
    )

    if ($match.Count -gt 0) {
        throw $Message
    }
}

function Invoke-ExpectedHttpError {
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
        [int]$ExpectedStatus,

        [byte[]]$Body = $null
    )

    try {
        $parameters = @{
            Uri = $Uri
            Method = $Method
            WebSession = $WebSession
            Headers = $Headers
            UseBasicParsing = $true
            ErrorAction = "Stop"
        }

        if ($null -ne $Body) {
            $parameters["ContentType"] = `
                "application/json; charset=utf-8"

            $parameters["Body"] = $Body
        }

        $response = Invoke-WebRequest @parameters

        throw @"
Se esperaba HTTP $ExpectedStatus,
pero la solicitud respondio HTTP $($response.StatusCode).
"@
    }
    catch {
        $response = $_.Exception.Response

        if ($null -eq $response) {
            throw
        }

        $statusCode = [int]$response.StatusCode

        if ($statusCode -ne $ExpectedStatus) {
            $bodyText = Get-ErrorResponseBody -ErrorRecord $_

            throw @"
Se esperaba HTTP $ExpectedStatus,
pero se recibio HTTP $statusCode.

Respuesta:
$bodyText
"@
        }

        return $statusCode
    }
}

function New-IsolatedAuthenticatedSession {
    param(
        [Parameter(Mandatory = $true)]
        [Microsoft.PowerShell.Commands.WebRequestSession]
        $SourceSession,

        [Parameter(Mandatory = $true)]
        [string]$BaseUrl
    )

    $targetSession = New-Object `
        Microsoft.PowerShell.Commands.WebRequestSession

    $baseUri = [System.Uri]$BaseUrl

    $cookies = $SourceSession.Cookies.GetCookies($baseUri)

    foreach ($cookie in $cookies) {
        $newCookie = New-Object System.Net.Cookie(
            $cookie.Name,
            $cookie.Value,
            $cookie.Path
        )

        $targetSession.Cookies.Add(
            $baseUri,
            $newCookie
        )
    }

    return $targetSession
}

Write-Host ""
Write-Host "========================================"
Write-Host " CONTROL ESCOLAR - PRUEBAS API"
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
    throw @"
No existe el estado de prueba de Grades:

$stateFile

Ejecuta primero:

.\scripts\Create-GradesTestData.ps1
"@
}

try {
    Write-Host "1. Cargando estado preparado..."

    $state = Get-Content `
        -LiteralPath $stateFile `
        -Raw |
        ConvertFrom-Json

    Assert-Equal `
        -Actual $state.module `
        -Expected "grades" `
        -Message "El archivo de estado no pertenece al modulo Grades."

    Assert-Equal `
        -Actual $state.test.created_by_api_test `
        -Expected $true `
        -Message "El estado no esta marcado como fixture de prueba."

    Assert-Equal `
        -Actual $state.subject.created_by_api_test `
        -Expected $true `
        -Message "El Subject no esta marcado como temporal."

    Assert-Equal `
        -Actual $state.teaching_assignment.created_by_api_test `
        -Expected $true `
        -Message "La TeachingAssignment no esta marcada como temporal."

    Assert-Equal `
        -Actual $state.assessment.created_by_api_test `
        -Expected $true `
        -Message "La Assessment no esta marcada como temporal."

    Assert-Equal `
        -Actual $state.grade.created_by_api_test `
        -Expected $true `
        -Message "La Grade no esta marcada como temporal."

    $suffix = [string]$state.test.suffix

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

    $enrollmentNumber = [string](
        $state.dependencies.enrollment.enrollment_number
    )

    $subjectId = [string]$state.subject.id
    $subjectCode = [string]$state.subject.code

    $teachingAssignmentId = [string](
        $state.teaching_assignment.id
    )

    $assessmentId = [string]$state.assessment.id

    $gradeId = [string]$state.grade.id

    foreach (
        $requiredValue in @(
            $suffix,
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
    Write-Host "   Grade ID:      $gradeId"
    Write-Host "   Assessment ID: $assessmentId"
    Write-Host "   Enrollment ID: $enrollmentId"
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
    Write-Host "   Usuario: $($currentUser.user.email)"
    Write-Host ""

    Write-Host "3. Consultando Grade temporal por UUID..."

    $gradeResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/grades/$gradeId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $campusHeaders

    if (-not $gradeResponse.data) {
        throw "La API no devolvio la Grade temporal."
    }

    Assert-Equal `
        -Actual $gradeResponse.data.id `
        -Expected $gradeId `
        -Message "La API devolvio otra Grade."

    Assert-Equal `
        -Actual $gradeResponse.data.assessment_id `
        -Expected $assessmentId `
        -Message "La Grade tiene otro Assessment."

    Assert-Equal `
        -Actual $gradeResponse.data.enrollment_id `
        -Expected $enrollmentId `
        -Message "La Grade tiene otro Enrollment."

    Assert-Equal `
        -Actual $gradeResponse.data.graded_by_teacher_id `
        -Expected $teacherId `
        -Message "La Grade fue asociada a otro profesor."

    Write-Host "   OK"
    Write-Host ""

    $studentFullName = [string](
        $gradeResponse.data.enrollment.student.full_name
    )

    if ([string]::IsNullOrWhiteSpace($studentFullName)) {
        throw "GradeResource no devolvio full_name del alumno."
    }

    Write-Host "4. Verificando rechazo de Grade duplicada..."

    $duplicateBody = @{
        assessment_id = $assessmentId
        enrollment_id = $enrollmentId
        score = 75
        feedback = "Intento duplicado $suffix"
        status = "graded"
    } | ConvertTo-Json -Depth 10

    $duplicateStatus = Invoke-ExpectedHttpError `
        -Uri "$backendUrl/api/grades" `
        -Method "Post" `
        -WebSession $global:webSession `
        -Headers $campusHeaders `
        -ExpectedStatus 422 `
        -Body (
            [System.Text.Encoding]::UTF8.GetBytes(
                $duplicateBody
            )
        )

    Assert-Equal `
        -Actual $duplicateStatus `
        -Expected 422 `
        -Message "La Grade duplicada no fue rechazada con 422."

    Write-Host "   OK"
    Write-Host "   HTTP: 422"
    Write-Host ""

    Write-Host "5. Actualizando exclusivamente Grade temporal..."

    [decimal]$updatedScore = 88.50
    $updatedFeedback = "Grade actualizada por Test-GradesApi.ps1 $suffix"

    $updateBody = @{
        score = $updatedScore
        feedback = $updatedFeedback
        status = "graded"
    } | ConvertTo-Json -Depth 10

    $updateResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/grades/$gradeId" `
        -Method Patch `
        -WebSession $global:webSession `
        -Headers $campusHeaders `
        -ContentType "application/json; charset=utf-8" `
        -Body (
            [System.Text.Encoding]::UTF8.GetBytes(
                $updateBody
            )
        )

    Assert-Equal `
        -Actual $updateResponse.data.id `
        -Expected $gradeId `
        -Message "PATCH devolvio otra Grade."

    Assert-Equal `
        -Actual $updateResponse.data.status `
        -Expected "graded" `
        -Message "PATCH no mantuvo status graded."

    if (
        [decimal]$updateResponse.data.score `
            -ne $updatedScore
    ) {
        throw "PATCH no devolvio score 88.50."
    }

    Write-Host "   OK"
    Write-Host ""

    Write-Host "6. Confirmando persistencia con GET nuevo..."

    $freshGrade = Invoke-RestMethod `
        -Uri "$backendUrl/api/grades/$gradeId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $campusHeaders

    Assert-Equal `
        -Actual $freshGrade.data.id `
        -Expected $gradeId `
        -Message "GET posterior devolvio otra Grade."

    if (
        [decimal]$freshGrade.data.score `
            -ne $updatedScore
    ) {
        throw @"
La puntuacion actualizada no persistio.

Esperado:
88.50

Recibido:
$($freshGrade.data.score)
"@
    }

    Assert-Equal `
        -Actual $freshGrade.data.feedback `
        -Expected $updatedFeedback `
        -Message "El feedback actualizado no persistio."

    Assert-Equal `
        -Actual $freshGrade.data.status `
        -Expected "graded" `
        -Message "El status actualizado no persistio."

    Assert-Equal `
        -Actual $freshGrade.data.graded_by_teacher_id `
        -Expected $teacherId `
        -Message "El profesor calificador cambio inesperadamente."

    Write-Host "   OK"
    Write-Host "   Score:    $($freshGrade.data.score)"
    Write-Host "   Status:   $($freshGrade.data.status)"
    Write-Host ""

    Write-Host "7. Probando filtro status=graded..."

    $statusResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/grades?status=graded&per_page=100" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $campusHeaders

    Assert-CollectionContainsId `
        -Response $statusResponse `
        -ExpectedId $gradeId `
        -Message "El filtro status=graded no contiene la Grade temporal."

    Write-Host "   OK"
    Write-Host ""

    Write-Host "8. Probando filtro assessment_id..."

    $assessmentFilter = Invoke-RestMethod `
        -Uri (
            "$backendUrl/api/grades?assessment_id=" +
            [System.Uri]::EscapeDataString($assessmentId) +
            "&per_page=100"
        ) `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $campusHeaders

    Assert-CollectionContainsId `
        -Response $assessmentFilter `
        -ExpectedId $gradeId `
        -Message "El filtro assessment_id no contiene la Grade temporal."

    Write-Host "   OK"
    Write-Host ""

    Write-Host "9. Probando filtro enrollment_id..."

    $enrollmentFilter = Invoke-RestMethod `
        -Uri (
            "$backendUrl/api/grades?enrollment_id=" +
            [System.Uri]::EscapeDataString($enrollmentId) +
            "&per_page=100"
        ) `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $campusHeaders

    Assert-CollectionContainsId `
        -Response $enrollmentFilter `
        -ExpectedId $gradeId `
        -Message "El filtro enrollment_id no contiene la Grade temporal."

    Write-Host "   OK"
    Write-Host ""

    Write-Host "10. Probando filtro student_id..."

    $studentFilter = Invoke-RestMethod `
        -Uri (
            "$backendUrl/api/grades?student_id=" +
            [System.Uri]::EscapeDataString($studentId) +
            "&per_page=100"
        ) `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $campusHeaders

    Assert-CollectionContainsId `
        -Response $studentFilter `
        -ExpectedId $gradeId `
        -Message "El filtro student_id no contiene la Grade temporal."

    Write-Host "   OK"
    Write-Host ""

    Write-Host "11. Probando filtro teaching_assignment_id..."

    $assignmentFilter = Invoke-RestMethod `
        -Uri (
            "$backendUrl/api/grades?teaching_assignment_id=" +
            [System.Uri]::EscapeDataString($teachingAssignmentId) +
            "&per_page=100"
        ) `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $campusHeaders

    Assert-CollectionContainsId `
        -Response $assignmentFilter `
        -ExpectedId $gradeId `
        -Message "El filtro teaching_assignment_id no contiene la Grade temporal."

    Write-Host "   OK"
    Write-Host ""

    Write-Host "12. Probando filtro grading_period_id..."

    $periodFilter = Invoke-RestMethod `
        -Uri (
            "$backendUrl/api/grades?grading_period_id=" +
            [System.Uri]::EscapeDataString($gradingPeriodId) +
            "&per_page=100"
        ) `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $campusHeaders

    Assert-CollectionContainsId `
        -Response $periodFilter `
        -ExpectedId $gradeId `
        -Message "El filtro grading_period_id no contiene la Grade temporal."

    Write-Host "   OK"
    Write-Host ""

    Write-Host "13. Probando busqueda por nombre completo del alumno..."

    $encodedSearch = [System.Uri]::EscapeDataString(
        $studentFullName
    )

    $searchResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/grades?search=$encodedSearch&per_page=100" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $campusHeaders

    Assert-CollectionContainsId `
        -Response $searchResponse `
        -ExpectedId $gradeId `
        -Message "La busqueda por nombre no contiene la Grade temporal."

    Write-Host "   OK"
    Write-Host "   Alumno: $studentFullName"
    Write-Host ""

    Write-Host "14. Verificando aislamiento por status incompatible..."

    $cancelledResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/grades?status=cancelled&per_page=100" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $campusHeaders

    Assert-CollectionDoesNotContainId `
        -Response $cancelledResponse `
        -UnexpectedId $gradeId `
        -Message "La Grade graded aparecio en status=cancelled."

    Write-Host "   OK"
    Write-Host ""

    Write-Host "15. Verificando X-Campus-ID obligatorio..."

    $isolatedSession = New-IsolatedAuthenticatedSession `
        -SourceSession $global:webSession `
        -BaseUrl $backendUrl

    $isolatedHeaders = @{}

    foreach ($key in $global:authHeaders.Keys) {
        if (
            [string]$key `
                -ne "X-Campus-ID"
        ) {
            $isolatedHeaders[$key] = `
                $global:authHeaders[$key]
        }
    }

    if (
        $isolatedHeaders.ContainsKey(
            "X-Campus-ID"
        )
    ) {
        $isolatedHeaders.Remove(
            "X-Campus-ID"
        )
    }

    $missingCampusStatus = Invoke-ExpectedHttpError `
        -Uri "$backendUrl/api/grades/$gradeId" `
        -Method "Get" `
        -WebSession $isolatedSession `
        -Headers $isolatedHeaders `
        -ExpectedStatus 422

    Assert-Equal `
        -Actual $missingCampusStatus `
        -Expected 422 `
        -Message "La ruta individual no exige X-Campus-ID."

    Write-Host "   OK"
    Write-Host "   HTTP: 422"
    Write-Host ""

    Write-Host "16. Confirmando que los fixtures permanecen..."

    $finalGrade = Invoke-RestMethod `
        -Uri "$backendUrl/api/grades/$gradeId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $campusHeaders

    Assert-Equal `
        -Actual $finalGrade.data.id `
        -Expected $gradeId `
        -Message "La Grade temporal desaparecio durante las pruebas."

    $finalAssessment = Invoke-RestMethod `
        -Uri "$backendUrl/api/assessments/$assessmentId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $campusHeaders

    Assert-Equal `
        -Actual $finalAssessment.data.id `
        -Expected $assessmentId `
        -Message "La Assessment temporal desaparecio durante las pruebas."

    $finalAssignment = Invoke-RestMethod `
        -Uri "$backendUrl/api/teaching-assignments/$teachingAssignmentId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $campusHeaders

    Assert-Equal `
        -Actual $finalAssignment.data.id `
        -Expected $teachingAssignmentId `
        -Message "La TeachingAssignment temporal desaparecio durante las pruebas."

    $finalSubject = Invoke-RestMethod `
        -Uri "$backendUrl/api/subjects/$subjectId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $campusHeaders

    Assert-Equal `
        -Actual $finalSubject.data.id `
        -Expected $subjectId `
        -Message "El Subject temporal desaparecio durante las pruebas."

    Assert-Equal `
        -Actual $finalSubject.data.code `
        -Expected $subjectCode `
        -Message "El Subject temporal ya no coincide con el estado."

    Write-Host "   OK"
    Write-Host ""

    Write-Host "========================================"
    Write-Host " RESULTADO: MODULO GRADES OK" `
        -ForegroundColor Green
    Write-Host "========================================"
    Write-Host ""

    Write-Host "Grade temporal preservada:"
    Write-Host "  $gradeId"
    Write-Host ""

    Write-Host "Assessment temporal preservada:"
    Write-Host "  $assessmentId"
    Write-Host ""

    Write-Host "Teaching Assignment temporal preservada:"
    Write-Host "  $teachingAssignmentId"
    Write-Host ""

    Write-Host "Subject temporal preservado:"
    Write-Host "  $subjectId"
    Write-Host ""

    Write-Host (
        "No se modifico ninguna Grade academica " +
        "preexistente."
    )

    Write-Host (
        "Ejecutaremos Cleanup-GradesTestData.ps1 " +
        "solo despues de validar este resultado."
    )

    Write-Host ""
}
catch {
    Write-Host ""
    Write-Host "TEST GRADES FALLO" `
        -ForegroundColor Red

    Write-Host (
        Get-ErrorResponseBody `
            -ErrorRecord $_
    ) -ForegroundColor Red

    Write-Host ""
    Write-Host (
        "No se ejecuta cleanup automatico."
    ) -ForegroundColor Yellow

    Write-Host (
        "Los fixtures deben conservarse para diagnostico."
    ) -ForegroundColor Yellow

    throw
}
