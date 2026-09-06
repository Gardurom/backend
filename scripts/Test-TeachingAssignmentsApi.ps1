$ErrorActionPreference = "Stop"

$backendUrl = "http://127.0.0.1:8000"

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path

$stateFile = Join-Path `
    $scriptRoot `
    ".test-state\teaching-assignments.json"

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

Write-Host ""
Write-Host "========================================"
Write-Host " CONTROL ESCOLAR - PRUEBAS API"
Write-Host " MODULO: ASIGNACIONES DOCENTES"
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

.\scripts\Create-TeachingAssignmentsTestData.ps1
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
        -ne "teaching-assignments"
) {
    throw @"
El archivo de estado no pertenece al modulo TeachingAssignments.

Modulo encontrado:
$($state.module)
"@
}

if (
    $state.test.created_by_api_test `
        -ne $true
) {
    throw "El estado no esta marcado como dato temporal de prueba."
}

$campusId = [string]$state.campus.id

$schoolGroupId = [string](
    $state.dependencies.school_group.id
)

$teacherId = [string](
    $state.dependencies.teacher.id
)

$subjectId = [string](
    $state.dependencies.subject.id
)

$subjectCode = [string](
    $state.dependencies.subject.code
)

$assignmentId = [string](
    $state.teaching_assignment.id
)

$startsOn = [string](
    $state.teaching_assignment.starts_on
)

$originalEndsOn = [string](
    $state.teaching_assignment.ends_on
)

$testSuffix = [string](
    $state.test.suffix
)

if ([string]::IsNullOrWhiteSpace($campusId)) {
    throw "El estado no contiene Campus ID."
}

if ([string]::IsNullOrWhiteSpace($schoolGroupId)) {
    throw "El estado no contiene Group ID."
}

if ([string]::IsNullOrWhiteSpace($teacherId)) {
    throw "El estado no contiene Teacher ID."
}

if ([string]::IsNullOrWhiteSpace($subjectId)) {
    throw "El estado no contiene Subject ID."
}

if ([string]::IsNullOrWhiteSpace($subjectCode)) {
    throw "El estado no contiene codigo de materia."
}

if ([string]::IsNullOrWhiteSpace($assignmentId)) {
    throw "El estado no contiene Assignment ID."
}

if ([string]::IsNullOrWhiteSpace($startsOn)) {
    throw "El estado no contiene starts_on."
}

if ([string]::IsNullOrWhiteSpace($testSuffix)) {
    throw "El estado no contiene sufijo de prueba."
}

Write-Host "   OK"
Write-Host "   Campus ID:     $campusId"
Write-Host "   Assignment ID: $assignmentId"
Write-Host "   Group ID:      $schoolGroupId"
Write-Host "   Teacher ID:    $teacherId"
Write-Host "   Subject ID:    $subjectId"
Write-Host ""

$campusHeaders = $global:authHeaders.Clone()

$campusHeaders["Accept"] = "application/json"
$campusHeaders["X-Campus-ID"] = $campusId

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

Write-Host "3. Consultando asignacion preparada..."

Remove-Variable showResponse `
    -ErrorAction SilentlyContinue

$showResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/teaching-assignments/$assignmentId" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $campusHeaders

if (-not $showResponse.data) {
    throw "La API no devolvio data."
}

if (
    [string]$showResponse.data.id `
        -ne $assignmentId
) {
    throw "La API devolvio una asignacion diferente."
}

if (
    [string]$showResponse.data.school_group_id `
        -ne $schoolGroupId
) {
    throw "La asignacion contiene un grupo incorrecto."
}

if (
    [string]$showResponse.data.teacher_id `
        -ne $teacherId
) {
    throw "La asignacion contiene un profesor incorrecto."
}

if (
    [string]$showResponse.data.subject_id `
        -ne $subjectId
) {
    throw "La asignacion contiene una materia incorrecta."
}

if (
    [string]$showResponse.data.status `
        -ne "active"
) {
    throw "La asignacion preparada no esta activa."
}

Write-Host "   OK"
Write-Host ""

Write-Host "4. Verificando aislamiento por plantel..."

if (
    [string]$showResponse.data.school_group.school_cycle.campus_id `
        -ne $campusId
) {
    throw "La asignacion pertenece a otro plantel."
}

Write-Host "   OK"
Write-Host ""

Write-Host "5. Verificando dependencias..."

if (
    [string]$showResponse.data.school_group_id `
        -ne $schoolGroupId
) {
    throw "Group ID incorrecto."
}

if (
    [string]$showResponse.data.teacher_id `
        -ne $teacherId
) {
    throw "Teacher ID incorrecto."
}

if (
    [string]$showResponse.data.subject_id `
        -ne $subjectId
) {
    throw "Subject ID incorrecto."
}

Write-Host "   OK"
Write-Host ""

Write-Host "6. Actualizando asignacion mediante PATCH..."

$updatedEndsOn = (
    [datetime]::ParseExact(
        $startsOn,
        "yyyy-MM-dd",
        [System.Globalization.CultureInfo]::InvariantCulture
    )
).AddDays(60).ToString(
    "yyyy-MM-dd"
)

$updateBodyObject = @{
    ends_on = $updatedEndsOn
    status = "completed"
}

$updateBody = $updateBodyObject |
    ConvertTo-Json -Depth 10

Remove-Variable updatedResponse `
    -ErrorAction SilentlyContinue

$updatedResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/teaching-assignments/$assignmentId" `
    -Method Patch `
    -WebSession $global:webSession `
    -Headers $campusHeaders `
    -ContentType "application/json" `
    -Body $updateBody

if (-not $updatedResponse.data) {
    throw "La API no devolvio data despues del PATCH."
}

if (
    [string]$updatedResponse.data.id `
        -ne $assignmentId
) {
    throw "El PATCH devolvio otra asignacion."
}

if (
    [string]$updatedResponse.data.status `
        -ne "completed"
) {
    throw "El estado no fue actualizado a completed."
}

if (
    [string]$updatedResponse.data.ends_on `
        -ne $updatedEndsOn
) {
    throw @"
La fecha final no fue actualizada.

Esperado:
$updatedEndsOn

Recibido:
$($updatedResponse.data.ends_on)
"@
}

Write-Host "   OK"
Write-Host "   Estado: completed"
Write-Host "   Ends on: $updatedEndsOn"
Write-Host ""

Write-Host "7. Verificando persistencia mediante GET fresco..."

Remove-Variable persistedResponse `
    -ErrorAction SilentlyContinue

$persistedResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/teaching-assignments/$assignmentId" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $campusHeaders

if (-not $persistedResponse.data) {
    throw "La API no devolvio la asignacion actualizada."
}

if (
    [string]$persistedResponse.data.id `
        -ne $assignmentId
) {
    throw "El GET posterior devolvio otra asignacion."
}

if (
    [string]$persistedResponse.data.status `
        -ne "completed"
) {
    throw "El estado completed no persistio."
}

if (
    [string]$persistedResponse.data.ends_on `
        -ne $updatedEndsOn
) {
    throw "La fecha ends_on actualizada no persistio."
}

if (
    [string]$persistedResponse.data.school_group_id `
        -ne $schoolGroupId
) {
    throw "El grupo cambio inesperadamente."
}

if (
    [string]$persistedResponse.data.teacher_id `
        -ne $teacherId
) {
    throw "El profesor cambio inesperadamente."
}

if (
    [string]$persistedResponse.data.subject_id `
        -ne $subjectId
) {
    throw "La materia cambio inesperadamente."
}

Write-Host "   OK"
Write-Host ""

Write-Host "8. Buscando asignacion por codigo de materia..."

$encodedSearch = [Uri]::EscapeDataString(
    $subjectCode
)

Remove-Variable searchResponse `
    -ErrorAction SilentlyContinue

$searchResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/teaching-assignments?search=$encodedSearch&per_page=100" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $campusHeaders

$searchedAssignment = $searchResponse.data |
    Where-Object {
        [string]$_.id -eq $assignmentId
    } |
    Select-Object -First 1

if (-not $searchedAssignment) {
    throw @"
La asignacion no aparecio mediante search.

Codigo buscado:
$subjectCode
"@
}

Write-Host "   OK"
Write-Host ""

Write-Host "9. Verificando filtro por profesor..."

$encodedTeacherId = [Uri]::EscapeDataString(
    $teacherId
)

Remove-Variable teacherFilterResponse `
    -ErrorAction SilentlyContinue

$teacherFilterResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/teaching-assignments?teacher_id=$encodedTeacherId&per_page=100" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $campusHeaders

$teacherAssignment = $teacherFilterResponse.data |
    Where-Object {
        [string]$_.id -eq $assignmentId
    } |
    Select-Object -First 1

if (-not $teacherAssignment) {
    throw "El filtro por profesor no devolvio la asignacion."
}

Write-Host "   OK"
Write-Host ""

Write-Host "10. Verificando filtro por estado completed..."

Remove-Variable completedFilterResponse `
    -ErrorAction SilentlyContinue

$completedFilterResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/teaching-assignments?status=completed&per_page=100" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $campusHeaders

$completedAssignment = $completedFilterResponse.data |
    Where-Object {
        [string]$_.id -eq $assignmentId
    } |
    Select-Object -First 1

if (-not $completedAssignment) {
    throw "El filtro completed no devolvio la asignacion."
}

Write-Host "   OK"
Write-Host ""

Write-Host "11. Verificando exclusion del filtro active..."

Remove-Variable activeFilterResponse `
    -ErrorAction SilentlyContinue

$activeFilterResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/teaching-assignments?status=active&per_page=100" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $campusHeaders

$unexpectedActiveAssignment = $activeFilterResponse.data |
    Where-Object {
        [string]$_.id -eq $assignmentId
    } |
    Select-Object -First 1

if ($unexpectedActiveAssignment) {
    throw @"
La asignacion esta completed
pero aparecio en el filtro status=active.
"@
}

Write-Host "   OK"
Write-Host ""

Write-Host "12. Verificando X-Campus-ID obligatorio..."

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
        -Uri "$backendUrl/api/teaching-assignments/$assignmentId" `
        -Method Get `
        -WebSession $isolatedSession `
        -Headers $headersWithoutCampus

    throw @"
La API permitio consultar una asignacion
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

Write-Host "13. Confirmando que la asignacion siga preservada..."

Remove-Variable finalResponse `
    -ErrorAction SilentlyContinue

$finalResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/teaching-assignments/$assignmentId" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $campusHeaders

if (-not $finalResponse.data) {
    throw "La asignacion ya no puede consultarse."
}

if (
    [string]$finalResponse.data.id `
        -ne $assignmentId
) {
    throw "La consulta final devolvio otra asignacion."
}

if (
    [string]$finalResponse.data.status `
        -ne "completed"
) {
    throw "La asignacion no conserva estado completed."
}

if (
    [string]$finalResponse.data.ends_on `
        -ne $updatedEndsOn
) {
    throw "La asignacion no conserva ends_on actualizado."
}

Write-Host "   OK"
Write-Host ""

Write-Host "14. Confirmando que la materia temporal siga preservada..."

Remove-Variable subjectResponse `
    -ErrorAction SilentlyContinue

$subjectResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/subjects/$subjectId" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $campusHeaders

if (-not $subjectResponse.data) {
    throw "La materia temporal ya no existe."
}

if (
    [string]$subjectResponse.data.id `
        -ne $subjectId
) {
    throw "La consulta devolvio otra materia."
}

if (
    [string]$subjectResponse.data.code `
        -ne $subjectCode
) {
    throw "El codigo de la materia temporal cambio."
}

Write-Host "   OK"
Write-Host ""

Write-Host "========================================"
Write-Host " RESULTADO: MODULO TEACHING ASSIGNMENTS OK" `
    -ForegroundColor Green
Write-Host "========================================"
Write-Host ""
Write-Host "Carga de estado:          OK"
Write-Host "Sesion autenticada:       OK"
Write-Host "Consulta:                 OK"
Write-Host "Plantel:                  OK"
Write-Host "Dependencias:             OK"
Write-Host "Actualizacion PATCH:      OK"
Write-Host "Persistencia update:      OK"
Write-Host "Busqueda:                 OK"
Write-Host "Filtro profesor:          OK"
Write-Host "Filtro completed:         OK"
Write-Host "Exclusion active:         OK"
Write-Host "Campus requerido:         OK"
Write-Host "Asignacion preservada:    OK"
Write-Host "Materia preservada:       OK"
Write-Host ""
Write-Host "Assignment ID: $assignmentId"
Write-Host "Subject ID:    $subjectId"
Write-Host ""
Write-Host "La asignacion NO fue eliminada."
Write-Host "La materia temporal NO fue eliminada."
Write-Host ""
Write-Host "Siguiente fase: Cleanup-TeachingAssignmentsTestData.ps1"
Write-Host ""