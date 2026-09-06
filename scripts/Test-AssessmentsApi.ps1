$ErrorActionPreference = "Stop"

$backendUrl = "http://127.0.0.1:8000"

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path

$stateFile = Join-Path `
    $scriptRoot `
    ".test-state\assessments.json"

function Get-HttpStatusCode {
    param(
        [Parameter(Mandatory = $true)]
        $ErrorRecord
    )

    if (
        $ErrorRecord.Exception.Response `
        -and $ErrorRecord.Exception.Response.StatusCode
    ) {
        return [int]$ErrorRecord.Exception.Response.StatusCode
    }

    return $null
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

Write-Host ""
Write-Host "========================================"
Write-Host " CONTROL ESCOLAR - PRUEBAS API"
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

if (-not (Test-Path -LiteralPath $stateFile)) {
    throw @"
No existe el archivo de estado:

$stateFile

Ejecuta primero:

.\scripts\Create-AssessmentsTestData.ps1
"@
}

Write-Host "1. Cargando estado preparado..."

$stateJson = [System.IO.File]::ReadAllText(
    $stateFile
)

$state = $stateJson |
    ConvertFrom-Json

if (
    [string]$state.module `
        -ne "assessments"
) {
    throw @"
El archivo de estado no pertenece al modulo Assessments.

Modulo encontrado:
$($state.module)
"@
}

if (
    $state.test.created_by_api_test `
        -ne $true
) {
    throw "El estado no esta marcado como dato temporal."
}

$campusId = [string]$state.campus.id

$teachingAssignmentId = [string](
    $state.dependencies.teaching_assignment.id
)

$gradingPeriodId = [string](
    $state.dependencies.grading_period.id
)

$assessmentId = [string](
    $state.assessment.id
)

$originalName = [string](
    $state.assessment.name
)

$testSuffix = [string](
    $state.test.suffix
)

if ([string]::IsNullOrWhiteSpace($campusId)) {
    throw "El estado no contiene Campus ID."
}

if (
    [string]::IsNullOrWhiteSpace(
        $teachingAssignmentId
    )
) {
    throw "El estado no contiene Teaching Assignment ID."
}

if (
    [string]::IsNullOrWhiteSpace(
        $gradingPeriodId
    )
) {
    throw "El estado no contiene Grading Period ID."
}

if (
    [string]::IsNullOrWhiteSpace(
        $assessmentId
    )
) {
    throw "El estado no contiene Assessment ID."
}

if (
    [string]::IsNullOrWhiteSpace(
        $testSuffix
    )
) {
    throw "El estado no contiene sufijo de prueba."
}

Write-Host "   OK"
Write-Host "   Campus ID:              $campusId"
Write-Host "   Assessment ID:          $assessmentId"
Write-Host "   Teaching Assignment ID: $teachingAssignmentId"
Write-Host "   Grading Period ID:      $gradingPeriodId"
Write-Host ""

$assessmentHeaders = $global:authHeaders.Clone()

$assessmentHeaders["Accept"] = "application/json"
$assessmentHeaders["X-Campus-ID"] = $campusId

Write-Host "2. Verificando sesion autenticada..."

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

Write-Host "3. Consultando evaluacion preparada..."

Remove-Variable showResponse `
    -ErrorAction SilentlyContinue

$showResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/assessments/$assessmentId" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $assessmentHeaders

if (-not $showResponse.data) {
    throw "La API no devolvio la evaluacion."
}

if (
    [string]$showResponse.data.id `
        -ne $assessmentId
) {
    throw "La API devolvio otra evaluacion."
}

if (
    [string]$showResponse.data.teaching_assignment.id `
        -ne $teachingAssignmentId
) {
    throw "La evaluacion contiene otra asignacion docente."
}

if (
    [string]$showResponse.data.grading_period.id `
        -ne $gradingPeriodId
) {
    throw "La evaluacion contiene otro periodo."
}

if (
    [string]$showResponse.data.name `
        -ne $originalName
) {
    throw "El nombre inicial no coincide con el estado."
}

if (
    [string]$showResponse.data.type `
        -ne "quiz"
) {
    throw "El tipo inicial no es quiz."
}

if (
    [string]$showResponse.data.status `
        -ne "cancelled"
) {
    throw "El estado inicial no es cancelled."
}

Write-Host "   OK"
Write-Host ""

Write-Host "4. Verificando dependencias..."

Remove-Variable assignmentResponse `
    -ErrorAction SilentlyContinue

$assignmentResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/teaching-assignments/$teachingAssignmentId" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $assessmentHeaders

if (-not $assignmentResponse.data) {
    throw "La asignacion docente ya no existe."
}

if (
    [string]$assignmentResponse.data.id `
        -ne $teachingAssignmentId
) {
    throw "La API devolvio otra asignacion docente."
}

if (
    [string]$assignmentResponse.data.school_group.school_cycle.campus_id `
        -ne $campusId
) {
    throw "La asignacion docente pertenece a otro plantel."
}

Write-Host "   OK"
Write-Host ""

Write-Host "5. Actualizando evaluacion mediante PATCH..."

$updatedName = "Evaluacion actualizada $testSuffix"

$updatedDueAt = (Get-Date).AddDays(14).ToString(
    "yyyy-MM-ddTHH:mm:sszzz"
)

$updateBodyObject = @{
    name = $updatedName

    description = "Evaluacion temporal actualizada por Test-AssessmentsApi.ps1."

    type = "exam"

    maximum_score = 50

    weight = 15

    due_at = $updatedDueAt

    status = "cancelled"
}

$updateBody = $updateBodyObject |
    ConvertTo-Json -Depth 10

Remove-Variable updatedResponse `
    -ErrorAction SilentlyContinue

$updatedResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/assessments/$assessmentId" `
    -Method Patch `
    -WebSession $global:webSession `
    -Headers $assessmentHeaders `
    -ContentType "application/json" `
    -Body $updateBody

if (-not $updatedResponse.data) {
    throw "La API no devolvio data despues del PATCH."
}

if (
    [string]$updatedResponse.data.id `
        -ne $assessmentId
) {
    throw "El PATCH devolvio otra evaluacion."
}

if (
    [string]$updatedResponse.data.name `
        -ne $updatedName
) {
    throw "El nombre no fue actualizado."
}

if (
    [string]$updatedResponse.data.type `
        -ne "exam"
) {
    throw "El tipo no fue actualizado a exam."
}

if (
    [decimal]$updatedResponse.data.maximum_score `
        -ne [decimal]50
) {
    throw "maximum_score no fue actualizado a 50."
}

if (
    [decimal]$updatedResponse.data.weight `
        -ne [decimal]15
) {
    throw "weight no fue actualizado a 15."
}

if (
    [string]$updatedResponse.data.status `
        -ne "cancelled"
) {
    throw "El estado no se conserva como cancelled."
}

Write-Host "   OK"
Write-Host "   Nombre:        $updatedName"
Write-Host "   Tipo:          exam"
Write-Host "   Maximum score: 50"
Write-Host "   Weight:        15"
Write-Host ""

Write-Host "6. Verificando persistencia mediante GET fresco..."

Remove-Variable persistedResponse `
    -ErrorAction SilentlyContinue

$persistedResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/assessments/$assessmentId" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $assessmentHeaders

if (-not $persistedResponse.data) {
    throw "La evaluacion actualizada no puede consultarse."
}

if (
    [string]$persistedResponse.data.name `
        -ne $updatedName
) {
    throw "El nombre actualizado no persistio."
}

if (
    [string]$persistedResponse.data.type `
        -ne "exam"
) {
    throw "El tipo exam no persistio."
}

if (
    [decimal]$persistedResponse.data.maximum_score `
        -ne [decimal]50
) {
    throw "maximum_score=50 no persistio."
}

if (
    [decimal]$persistedResponse.data.weight `
        -ne [decimal]15
) {
    throw "weight=15 no persistio."
}

if (
    [string]$persistedResponse.data.status `
        -ne "cancelled"
) {
    throw "El estado cancelled no persistio."
}

if (
    [string]$persistedResponse.data.teaching_assignment.id `
        -ne $teachingAssignmentId
) {
    throw "La asignacion docente cambio inesperadamente."
}

if (
    [string]$persistedResponse.data.grading_period.id `
        -ne $gradingPeriodId
) {
    throw "El periodo cambio inesperadamente."
}

Write-Host "   OK"
Write-Host ""

Write-Host "7. Buscando evaluacion por nombre..."

$encodedSearch = [Uri]::EscapeDataString(
    $updatedName
)

Remove-Variable searchResponse `
    -ErrorAction SilentlyContinue

$searchResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/assessments?search=$encodedSearch&per_page=100" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $assessmentHeaders

$searchedAssessment = $searchResponse.data |
    Where-Object {
        [string]$_.id -eq $assessmentId
    } |
    Select-Object -First 1

if (-not $searchedAssessment) {
    throw "La evaluacion no aparecio mediante search."
}

Write-Host "   OK"
Write-Host ""

Write-Host "8. Verificando filtro por asignacion docente..."

$encodedAssignmentId = [Uri]::EscapeDataString(
    $teachingAssignmentId
)

Remove-Variable assignmentFilterResponse `
    -ErrorAction SilentlyContinue

$assignmentFilterResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/assessments?teaching_assignment_id=$encodedAssignmentId&per_page=100" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $assessmentHeaders

$assignmentAssessment = $assignmentFilterResponse.data |
    Where-Object {
        [string]$_.id -eq $assessmentId
    } |
    Select-Object -First 1

if (-not $assignmentAssessment) {
    throw "El filtro por asignacion no devolvio la evaluacion."
}

Write-Host "   OK"
Write-Host ""

Write-Host "9. Verificando filtro por periodo..."

$encodedPeriodId = [Uri]::EscapeDataString(
    $gradingPeriodId
)

Remove-Variable periodFilterResponse `
    -ErrorAction SilentlyContinue

$periodFilterResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/assessments?grading_period_id=$encodedPeriodId&per_page=100" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $assessmentHeaders

$periodAssessment = $periodFilterResponse.data |
    Where-Object {
        [string]$_.id -eq $assessmentId
    } |
    Select-Object -First 1

if (-not $periodAssessment) {
    throw "El filtro por periodo no devolvio la evaluacion."
}

Write-Host "   OK"
Write-Host ""

Write-Host "10. Verificando filtro por tipo exam..."

Remove-Variable typeFilterResponse `
    -ErrorAction SilentlyContinue

$typeFilterResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/assessments?type=exam&per_page=100" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $assessmentHeaders

$typeAssessment = $typeFilterResponse.data |
    Where-Object {
        [string]$_.id -eq $assessmentId
    } |
    Select-Object -First 1

if (-not $typeAssessment) {
    throw "El filtro type=exam no devolvio la evaluacion."
}

Write-Host "   OK"
Write-Host ""

Write-Host "11. Verificando exclusion del filtro quiz..."

Remove-Variable quizFilterResponse `
    -ErrorAction SilentlyContinue

$quizFilterResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/assessments?type=quiz&per_page=100" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $assessmentHeaders

$unexpectedQuizAssessment = $quizFilterResponse.data |
    Where-Object {
        [string]$_.id -eq $assessmentId
    } |
    Select-Object -First 1

if ($unexpectedQuizAssessment) {
    throw @"
La evaluacion fue actualizada a exam
pero aparece en el filtro type=quiz.
"@
}

Write-Host "   OK"
Write-Host ""

Write-Host "12. Verificando filtro por estado cancelled..."

Remove-Variable statusFilterResponse `
    -ErrorAction SilentlyContinue

$statusFilterResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/assessments?status=cancelled&per_page=100" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $assessmentHeaders

$statusAssessment = $statusFilterResponse.data |
    Where-Object {
        [string]$_.id -eq $assessmentId
    } |
    Select-Object -First 1

if (-not $statusAssessment) {
    throw "El filtro status=cancelled no devolvio la evaluacion."
}

Write-Host "   OK"
Write-Host ""

Write-Host "13. Verificando X-Campus-ID obligatorio..."

$isolatedSession = New-Object `
    Microsoft.PowerShell.Commands.WebRequestSession

$baseUri = [Uri]$backendUrl

$sourceCookies = $global:webSession.Cookies.GetCookies(
    $baseUri
)

foreach ($cookie in $sourceCookies) {
    $cookieCopy = New-Object System.Net.Cookie

    $cookieCopy.Name = $cookie.Name
    $cookieCopy.Value = $cookie.Value
    $cookieCopy.Path = $cookie.Path

    if (
        [string]::IsNullOrWhiteSpace(
            [string]$cookie.Domain
        )
    ) {
        $cookieCopy.Domain = $baseUri.Host
    }
    else {
        $cookieCopy.Domain = $cookie.Domain
    }

    $cookieCopy.Secure = $cookie.Secure
    $cookieCopy.HttpOnly = $cookie.HttpOnly

    $isolatedSession.Cookies.Add(
        $cookieCopy
    )
}

$headersWithoutCampus = @{}

foreach ($key in $global:authHeaders.Keys) {
    if (
        [string]$key `
            -ne "X-Campus-ID"
    ) {
        $headersWithoutCampus[$key] = `
            $global:authHeaders[$key]
    }
}

$headersWithoutCampus["Accept"] = "application/json"

$campusRequiredConfirmed = $false
$campusRequiredStatus = $null

try {
    Remove-Variable noCampusResponse `
        -ErrorAction SilentlyContinue

    $noCampusResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/assessments/$assessmentId" `
        -Method Get `
        -WebSession $isolatedSession `
        -Headers $headersWithoutCampus

    throw @"
La API permitio consultar una evaluacion
sin X-Campus-ID.
"@
}
catch {
    $campusRequiredStatus = Get-HttpStatusCode `
        -ErrorRecord $_

    if (
        $campusRequiredStatus `
            -eq 422
    ) {
        $campusRequiredConfirmed = $true
    }
    elseif (
        $_.Exception.Message -like `
            "*permitio consultar*"
    ) {
        throw
    }
    else {
        Write-Host (
            Get-ErrorResponseBody `
                -ErrorRecord $_
        ) -ForegroundColor Red

        throw @"
Se esperaba HTTP 422 al omitir X-Campus-ID.

HTTP recibido:
$campusRequiredStatus
"@
    }
}

if (-not $campusRequiredConfirmed) {
    throw "No fue posible confirmar X-Campus-ID obligatorio."
}

Write-Host "   OK"
Write-Host "   HTTP: 422"
Write-Host ""

Write-Host "14. Confirmando que la evaluacion siga preservada..."

Remove-Variable finalResponse `
    -ErrorAction SilentlyContinue

$finalResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/assessments/$assessmentId" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $assessmentHeaders

if (-not $finalResponse.data) {
    throw "La evaluacion ya no puede consultarse."
}

if (
    [string]$finalResponse.data.id `
        -ne $assessmentId
) {
    throw "La consulta final devolvio otra evaluacion."
}

if (
    [string]$finalResponse.data.name `
        -ne $updatedName
) {
    throw "La evaluacion no conserva el nombre actualizado."
}

if (
    [string]$finalResponse.data.type `
        -ne "exam"
) {
    throw "La evaluacion no conserva el tipo exam."
}

if (
    [string]$finalResponse.data.status `
        -ne "cancelled"
) {
    throw "La evaluacion no conserva estado cancelled."
}

Write-Host "   OK"
Write-Host ""

Write-Host "========================================"
Write-Host " RESULTADO: MODULO ASSESSMENTS OK" `
    -ForegroundColor Green
Write-Host "========================================"
Write-Host ""
Write-Host "Carga de estado:           OK"
Write-Host "Sesion autenticada:        OK"
Write-Host "Consulta:                  OK"
Write-Host "Dependencias:              OK"
Write-Host "Actualizacion PATCH:       OK"
Write-Host "Persistencia update:       OK"
Write-Host "Busqueda:                  OK"
Write-Host "Filtro asignacion:         OK"
Write-Host "Filtro periodo:            OK"
Write-Host "Filtro exam:               OK"
Write-Host "Exclusion quiz:            OK"
Write-Host "Filtro cancelled:          OK"
Write-Host "Campus requerido:          OK"
Write-Host "Evaluacion preservada:     OK"
Write-Host ""
Write-Host "Assessment ID: $assessmentId"
Write-Host ""
Write-Host "La evaluacion NO fue eliminada."
Write-Host "La asignacion docente NO fue modificada."
Write-Host "El periodo de calificacion NO fue modificado."
Write-Host ""
Write-Host "Siguiente fase: Cleanup-AssessmentsTestData.ps1"
Write-Host ""