#requires -Version 5.1

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

Write-Host ""
Write-Host "========================================"
Write-Host " CONTROL ESCOLAR - CREATE TEST DATA"
Write-Host " MODULO: ASISTENCIAS"
Write-Host "========================================"
Write-Host ""

function Fail {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Message
    )

    throw $Message
}

function Get-HttpStatusCode {
    param(
        [Parameter(Mandatory = $true)]
        $Exception
    )

    try {
        if ($null -ne $Exception.Exception.Response) {
            return [int] $Exception.Exception.Response.StatusCode
        }
    }
    catch {
    }

    return $null
}

function Get-ErrorResponseBody {
    param(
        [Parameter(Mandatory = $true)]
        $Exception
    )

    try {
        $response = $Exception.Exception.Response

        if ($null -eq $response) {
            return $null
        }

        $stream = $response.GetResponseStream()

        if ($null -eq $stream) {
            return $null
        }

        $reader = New-Object System.IO.StreamReader($stream)

        try {
            return $reader.ReadToEnd()
        }
        finally {
            $reader.Dispose()
        }
    }
    catch {
        return $null
    }
}

function Invoke-Api {
    param(
        [Parameter(Mandatory = $true)]
        [ValidateSet("GET", "POST", "PUT", "PATCH", "DELETE")]
        [string] $Method,

        [Parameter(Mandatory = $true)]
        [string] $Uri,

        [Parameter(Mandatory = $true)]
        [hashtable] $Headers,

        [object] $Body = $null
    )

    $parameters = @{
        Method      = $Method
        Uri         = $Uri
        WebSession  = $global:webSession
        Headers     = $Headers
        ErrorAction = "Stop"
    }

    if ($null -ne $Body) {
        $json = $Body | ConvertTo-Json -Depth 20 -Compress
        $utf8Body = [System.Text.Encoding]::UTF8.GetBytes($json)

        $parameters["Body"] = $utf8Body
        $parameters["ContentType"] = "application/json; charset=utf-8"
    }

    return Invoke-RestMethod @parameters
}

function Get-CollectionData {
    param(
        [Parameter(Mandatory = $true)]
        $Response
    )

    if ($null -ne $Response.PSObject.Properties["data"]) {
        return @($Response.data)
    }

    return @($Response)
}

function Clone-Headers {
    param(
        [Parameter(Mandatory = $true)]
        [System.Collections.IDictionary] $Headers
    )

    $clone = @{}

    foreach ($key in $Headers.Keys) {
        $clone[[string] $key] = [string] $Headers[$key]
    }

    return $clone
}

function Get-CampusIdFromHeaders {
    param(
        [Parameter(Mandatory = $true)]
        [System.Collections.IDictionary] $Headers
    )

    foreach ($key in $Headers.Keys) {
        if (
            [string]::Equals(
                [string] $key,
                "X-Campus-ID",
                [System.StringComparison]::OrdinalIgnoreCase
            )
        ) {
            $value = [string] $Headers[$key]

            if (-not [string]::IsNullOrWhiteSpace($value)) {
                return $value.Trim()
            }
        }
    }

    return $null
}

function Get-AvailableStartTime {
    param(
        [Parameter(Mandatory = $false)]
        [AllowNull()]
        [AllowEmptyCollection()]
        [object[]] $ExistingSessions = @()
    )

    if ($null -eq $ExistingSessions) {
        $ExistingSessions = @()
    }

    $candidateTimes = @(
        "06:00",
        "06:30",
        "07:00",
        "07:30",
        "08:00",
        "08:30",
        "09:00",
        "09:30",
        "10:00",
        "10:30",
        "11:00",
        "11:30",
        "12:00",
        "12:30",
        "13:00",
        "13:30",
        "14:00",
        "14:30",
        "15:00",
        "15:30",
        "16:00",
        "16:30",
        "17:00",
        "17:30",
        "18:00",
        "18:30",
        "19:00",
        "19:30",
        "20:00",
        "20:30",
        "21:00",
        "21:30"
    )

    $usedTimes = @{}

    foreach ($session in $ExistingSessions) {
        if ($null -eq $session) {
            continue
        }

        $startsAt = [string] $session.starts_at

        if (-not [string]::IsNullOrWhiteSpace($startsAt)) {
            $normalized = $startsAt.Trim()

            if ($normalized.Length -ge 5) {
                $normalized = $normalized.Substring(0, 5)
            }

            $usedTimes[$normalized] = $true
        }
    }

    foreach ($candidate in $candidateTimes) {
        if (-not $usedTimes.ContainsKey($candidate)) {
            return $candidate
        }
    }

    return $null
}

function Add-MinutesToTime {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Time,

        [Parameter(Mandatory = $true)]
        [int] $Minutes
    )

    $parsed = [datetime]::ParseExact(
        $Time,
        "HH:mm",
        [System.Globalization.CultureInfo]::InvariantCulture
    )

    return $parsed.AddMinutes($Minutes).ToString("HH:mm")
}

if (
    (-not (Test-Path variable:global:webSession)) -or
    ($null -eq $global:webSession)
) {
    Fail "No existe `$global:webSession. Ejecute primero scripts\Test-Auth.ps1."
}

if (
    (-not (Test-Path variable:global:authHeaders)) -or
    ($null -eq $global:authHeaders)
) {
    Fail "No existe `$global:authHeaders. Ejecute primero scripts\Test-Auth.ps1."
}

$baseUrl = "http://127.0.0.1:8000"

if (
    (Test-Path variable:global:baseUrl) -and
    (-not [string]::IsNullOrWhiteSpace(
        [string] $global:baseUrl
    ))
) {
    $baseUrl = ([string] $global:baseUrl).TrimEnd("/")
}

$headers = Clone-Headers -Headers $global:authHeaders
$campusId = Get-CampusIdFromHeaders -Headers $headers

if ([string]::IsNullOrWhiteSpace($campusId)) {
    Write-Host "0. X-Campus-ID no esta en authHeaders; descubriendo plantel activo..." -ForegroundColor Cyan

    $campusHeaders = @{}

    if (
        (Test-Path variable:global:baseHeaders) -and
        ($null -ne $global:baseHeaders)
    ) {
        $campusHeaders = Clone-Headers -Headers $global:baseHeaders
    }
    else {
        $campusHeaders = Clone-Headers -Headers $global:authHeaders
    }

    try {
        $campusResponse = Invoke-Api `
            -Method "GET" `
            -Uri "$baseUrl/api/campuses?per_page=100" `
            -Headers $campusHeaders
    }
    catch {
        $statusCode = Get-HttpStatusCode -Exception $_
        $body = Get-ErrorResponseBody -Exception $_

        Fail "No fue posible descubrir un plantel activo. HTTP: $statusCode. Respuesta: $body"
    }

    $campuses = Get-CollectionData -Response $campusResponse

    $campus = $campuses |
        Where-Object {
            $null -ne $_ -and
            -not [string]::IsNullOrWhiteSpace([string] $_.id) -and
            (
                $null -eq $_.PSObject.Properties["is_active"] -or
                [bool] $_.is_active
            )
        } |
        Select-Object -First 1

    if ($null -eq $campus) {
        Fail "No se encontro ningun plantel activo accesible por API."
    }

    $campusId = ([string] $campus.id).Trim()
    $headers["X-Campus-ID"] = $campusId

    Write-Host "   OK" -ForegroundColor Green
    Write-Host "   Campus descubierto: $campusId"
}

$stateDirectory = Join-Path $PSScriptRoot ".test-state"
$statePath = Join-Path $stateDirectory "attendance.json"

if (Test-Path $statePath) {
    Fail "Ya existe el estado de Attendance: $statePath. Ejecute primero el cleanup correspondiente o revise el estado existente."
}

Write-Host "1. Verificando sesion autenticada..." -ForegroundColor Cyan

try {
    $me = Invoke-Api `
        -Method "GET" `
        -Uri "$baseUrl/api/auth/me" `
        -Headers $headers
}
catch {
    $statusCode = Get-HttpStatusCode -Exception $_
    $body = Get-ErrorResponseBody -Exception $_

    Fail "No fue posible verificar /api/auth/me. HTTP: $statusCode. Respuesta: $body"
}

if ($null -eq $me) {
    Fail "La verificacion de autenticacion no devolvio datos."
}

Write-Host "   OK" -ForegroundColor Green
Write-Host "   Campus: $campusId"

Write-Host ""
Write-Host "2. Buscando TeachingAssignment reutilizable..." -ForegroundColor Cyan

try {
    $assignmentResponse = Invoke-Api `
        -Method "GET" `
        -Uri "$baseUrl/api/teaching-assignments?per_page=100" `
        -Headers $headers
}
catch {
    $statusCode = Get-HttpStatusCode -Exception $_
    $body = Get-ErrorResponseBody -Exception $_

    Fail "No fue posible consultar TeachingAssignments. HTTP: $statusCode. Respuesta: $body"
}

$assignments = Get-CollectionData -Response $assignmentResponse

$assignment = $assignments |
    Where-Object {
        $null -ne $_ `
        -and -not [string]::IsNullOrWhiteSpace([string] $_.id) `
        -and -not [string]::IsNullOrWhiteSpace([string] $_.school_group_id) `
        -and -not [string]::IsNullOrWhiteSpace([string] $_.teacher_id) `
        -and (
            [string]::IsNullOrWhiteSpace([string] $_.status) `
            -or [string] $_.status -eq "active"
        ) `
        -and (
            $null -eq $_.teacher `
            -or [string]::IsNullOrWhiteSpace([string] $_.teacher.status) `
            -or [string] $_.teacher.status -eq "active"
        )
    } |
    Select-Object -First 1

if ($null -eq $assignment) {
    Fail "No se encontro una TeachingAssignment utilizable en el plantel autenticado."
}

$teachingAssignmentId = [string] $assignment.id
$schoolGroupId = [string] $assignment.school_group_id
$teacherId = [string] $assignment.teacher_id
$subjectId = [string] $assignment.subject_id

Write-Host "   OK" -ForegroundColor Green
Write-Host "   Teaching Assignment: $teachingAssignmentId"
Write-Host "   School Group:        $schoolGroupId"
Write-Host "   Teacher:             $teacherId"
Write-Host "   Subject:             $subjectId"

Write-Host ""
Write-Host "3. Seleccionando fecha y horario sin colision..." -ForegroundColor Cyan

$heldOn = (Get-Date).ToString("yyyy-MM-dd")

$filterUri = (
    "$baseUrl/api/attendance-sessions" +
    "?per_page=100" +
    "&teaching_assignment_id=$([System.Uri]::EscapeDataString($teachingAssignmentId))" +
    "&date_from=$heldOn" +
    "&date_to=$heldOn" +
    "&type=class"
)

try {
    $existingResponse = Invoke-Api `
        -Method "GET" `
        -Uri $filterUri `
        -Headers $headers
}
catch {
    $statusCode = Get-HttpStatusCode -Exception $_
    $body = Get-ErrorResponseBody -Exception $_

    Fail "No fue posible consultar sesiones existentes. HTTP: $statusCode. Respuesta: $body"
}

$existingSessions = @(Get-CollectionData -Response $existingResponse)
$startsAt = Get-AvailableStartTime -ExistingSessions @($existingSessions)

if ([string]::IsNullOrWhiteSpace($startsAt)) {
    Fail "No existe un horario de prueba disponible para la TeachingAssignment seleccionada en $heldOn."
}

$endsAt = Add-MinutesToTime -Time $startsAt -Minutes 45
$uniqueSuffix = Get-Date -Format "yyyyMMddHHmmssfff"
$notes = "API-TEST-ATTENDANCE-$uniqueSuffix"

Write-Host "   OK" -ForegroundColor Green
Write-Host "   Fecha:  $heldOn"
Write-Host "   Inicio: $startsAt"
Write-Host "   Fin:    $endsAt"

Write-Host ""
Write-Host "4. Creando AttendanceSession temporal..." -ForegroundColor Cyan

$createBody = @{
    school_group_id         = $schoolGroupId
    teaching_assignment_id = $teachingAssignmentId
    recorded_by_teacher_id = $teacherId
    held_on                 = $heldOn
    starts_at               = $startsAt
    ends_at                 = $endsAt
    type                    = "class"
    status                  = "scheduled"
    notes                   = $notes
}

try {
    $createResponse = Invoke-Api `
        -Method "POST" `
        -Uri "$baseUrl/api/attendance-sessions" `
        -Headers $headers `
        -Body $createBody
}
catch {
    $statusCode = Get-HttpStatusCode -Exception $_
    $body = Get-ErrorResponseBody -Exception $_

    Fail "No fue posible crear la AttendanceSession temporal. HTTP: $statusCode. Respuesta: $body"
}

if (
    $null -eq $createResponse `
    -or $null -eq $createResponse.data `
    -or [string]::IsNullOrWhiteSpace(
        [string] $createResponse.data.id
    )
) {
    Fail "La API no devolvio el UUID de la AttendanceSession creada."
}

$attendanceSessionId = [string] $createResponse.data.id

Write-Host "   OK" -ForegroundColor Green
Write-Host "   Attendance Session: $attendanceSessionId"

Write-Host ""
Write-Host "5. Verificando sesion creada y records automaticos..." -ForegroundColor Cyan

try {
    $sessionResponse = Invoke-Api `
        -Method "GET" `
        -Uri "$baseUrl/api/attendance-sessions/$attendanceSessionId" `
        -Headers $headers
}
catch {
    $statusCode = Get-HttpStatusCode -Exception $_
    $body = Get-ErrorResponseBody -Exception $_

    Fail "La sesion fue creada pero no pudo verificarse. HTTP: $statusCode. Respuesta: $body"
}

$session = $sessionResponse.data

if ($null -eq $session) {
    $session = $sessionResponse
}

if ([string] $session.id -ne $attendanceSessionId) {
    Fail "La API devolvio una sesion distinta a la creada."
}

if ([string] $session.school_group_id -ne $schoolGroupId) {
    Fail "La sesion creada no conserva el SchoolGroup esperado."
}

if (
    [string] $session.teaching_assignment_id `
    -ne $teachingAssignmentId
) {
    Fail "La sesion creada no conserva la TeachingAssignment esperada."
}

if ([string] $session.recorded_by_teacher_id -ne $teacherId) {
    Fail "La sesion creada no conserva el Teacher esperado."
}

if ([string] $session.type -ne "class") {
    Fail "La sesion temporal no tiene type=class."
}

if ([string] $session.status -ne "scheduled") {
    Fail "La sesion temporal no tiene status=scheduled."
}

if ([string] $session.notes -ne $notes) {
    Fail "La marca unica de la sesion temporal no coincide."
}

$records = @($session.records)

if ($records.Count -lt 1) {
    Fail "La sesion temporal no genero AttendanceRecords. Se requiere al menos un Enrollment activo."
}

foreach ($record in $records) {
    if ([string]::IsNullOrWhiteSpace([string] $record.id)) {
        Fail "Uno de los AttendanceRecords no contiene id."
    }

    if (
        [string]::IsNullOrWhiteSpace(
            [string] $record.enrollment_id
        )
    ) {
        Fail "Uno de los AttendanceRecords no contiene enrollment_id."
    }

    if ([string] $record.status -ne "pending") {
        Fail "Todos los AttendanceRecords deben iniciar en status=pending."
    }
}

$reportedRecordCount = $null

if (
    ($null -ne $session.PSObject.Properties["records_count"]) -and
    ($null -ne $session.records_count)
) {
    $reportedRecordCount = [int] $session.records_count

    if ($reportedRecordCount -ne $records.Count) {
        Fail "records_count no coincide con la cantidad de records devueltos."
    }
}

Write-Host "   OK" -ForegroundColor Green
Write-Host "   Records generados: $($records.Count)"
Write-Host "   Todos en estado:   pending"

Write-Host ""
Write-Host "6. Guardando estado exacto del fixture..." -ForegroundColor Cyan

if (-not (Test-Path $stateDirectory)) {
    New-Item `
        -ItemType Directory `
        -Path $stateDirectory `
        -Force |
        Out-Null
}

$stateRecords = @(
    foreach ($record in $records) {
        [ordered]@{
            id             = [string] $record.id
            enrollment_id  = [string] $record.enrollment_id
            initial_status = [string] $record.status
        }
    }
)

$state = [ordered]@{
    module = "attendance"
    created_at_utc = [datetime]::UtcNow.ToString(
        "yyyy-MM-ddTHH:mm:ss.fffffffZ"
    )

    campus = [ordered]@{
        id = $campusId
        created_by_api_test = $false
    }

    school_group = [ordered]@{
        id = $schoolGroupId
        created_by_api_test = $false
    }

    teaching_assignment = [ordered]@{
        id = $teachingAssignmentId
        created_by_api_test = $false
    }

    teacher = [ordered]@{
        id = $teacherId
        created_by_api_test = $false
    }

    subject = [ordered]@{
        id = $subjectId
        created_by_api_test = $false
    }

    attendance_session = [ordered]@{
        id = $attendanceSessionId
        created_by_api_test = $true
        held_on = $heldOn
        starts_at = $startsAt
        ends_at = $endsAt
        type = "class"
        initial_status = "scheduled"
        notes = $notes
    }

    attendance_records = [ordered]@{
        created_by_api_test = $true
        count = $records.Count
        items = $stateRecords
    }
}

$utf8NoBom = New-Object System.Text.UTF8Encoding($false)

[System.IO.File]::WriteAllText(
    $statePath,
    ($state | ConvertTo-Json -Depth 20),
    $utf8NoBom
)

if (-not (Test-Path $statePath)) {
    Fail "No fue posible crear el archivo de estado."
}

$loadedState = Get-Content `
    -Raw `
    -Path $statePath |
    ConvertFrom-Json

if (
    [string] $loadedState.attendance_session.id `
    -ne $attendanceSessionId
) {
    Fail "El archivo de estado no conserva el UUID exacto de la sesion."
}

if (
    [int] $loadedState.attendance_records.count `
    -ne $records.Count
) {
    Fail "El archivo de estado no conserva la cantidad exacta de records."
}

Write-Host "   OK" -ForegroundColor Green
Write-Host "   Estado: $statePath"

Write-Host ""
Write-Host "========================================"
Write-Host " RESULTADO: CREATE ATTENDANCE OK"
Write-Host "========================================"
Write-Host ""
Write-Host "Attendance Session temporal:"
Write-Host "  $attendanceSessionId"
Write-Host ""
Write-Host "Attendance Records temporales:"
Write-Host "  $($records.Count)"
Write-Host ""
Write-Host "Dependencias academicas reutilizadas:"
Write-Host "  School Group:        $schoolGroupId"
Write-Host "  Teaching Assignment: $teachingAssignmentId"
Write-Host "  Teacher:             $teacherId"
Write-Host "  Subject:             $subjectId"
Write-Host ""
Write-Host "No se modificaron datos academicos preexistentes."
Write-Host "No ejecute otro CREATE de Attendance antes del TEST/CLEANUP."
Write-Host ""
