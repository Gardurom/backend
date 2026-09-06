#requires -Version 5.1

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

Write-Host ""
Write-Host "========================================"
Write-Host " CONTROL ESCOLAR - API TEST"
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

function Pass {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Message
    )

    Write-Host "   OK - $Message" -ForegroundColor Green
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

function Remove-HeaderIgnoreCase {
    param(
        [Parameter(Mandatory = $true)]
        [hashtable] $Headers,

        [Parameter(Mandatory = $true)]
        [string] $Name
    )

    $matchingKeys = @(
        $Headers.Keys |
            Where-Object {
                [string]::Equals(
                    [string] $_,
                    $Name,
                    [System.StringComparison]::OrdinalIgnoreCase
                )
            }
    )

    foreach ($key in $matchingKeys) {
        $Headers.Remove($key)
    }
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

        [object] $Body = $null,

        [Microsoft.PowerShell.Commands.WebRequestSession] $WebSession = $global:webSession
    )

    $parameters = @{
        Method      = $Method
        Uri         = $Uri
        WebSession  = $WebSession
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

function Invoke-ExpectHttpError {
    param(
        [Parameter(Mandatory = $true)]
        [ValidateSet("GET", "POST", "PUT", "PATCH", "DELETE")]
        [string] $Method,

        [Parameter(Mandatory = $true)]
        [string] $Uri,

        [Parameter(Mandatory = $true)]
        [hashtable] $Headers,

        [Parameter(Mandatory = $true)]
        [int] $ExpectedStatus,

        [object] $Body = $null,

        [Microsoft.PowerShell.Commands.WebRequestSession] $WebSession = $global:webSession
    )

    try {
        $null = Invoke-Api `
            -Method $Method `
            -Uri $Uri `
            -Headers $Headers `
            -Body $Body `
            -WebSession $WebSession

        Fail "Se esperaba HTTP $ExpectedStatus, pero la solicitud fue exitosa."
    }
    catch {
        if (
            $_.Exception.Message -eq
            "Se esperaba HTTP $ExpectedStatus, pero la solicitud fue exitosa."
        ) {
            throw
        }

        $actualStatus = Get-HttpStatusCode -Exception $_
        $bodyText = Get-ErrorResponseBody -Exception $_

        if ($actualStatus -ne $ExpectedStatus) {
            Fail "Se esperaba HTTP $ExpectedStatus y se obtuvo HTTP $actualStatus. Respuesta: $bodyText"
        }

        return [pscustomobject]@{
            StatusCode = $actualStatus
            Body       = $bodyText
        }
    }
}

function Get-CollectionData {
    param(
        [Parameter(Mandatory = $true)]
        $Response
    )

    if (
        $null -ne $Response -and
        $null -ne $Response.PSObject.Properties["data"]
    ) {
        return @($Response.data)
    }

    return @($Response)
}

function Get-ResponseData {
    param(
        [Parameter(Mandatory = $true)]
        $Response
    )

    if (
        $null -ne $Response -and
        $null -ne $Response.PSObject.Properties["data"]
    ) {
        return $Response.data
    }

    return $Response
}

function Assert-ContainsSession {
    param(
        [Parameter(Mandatory = $true)]
        [object[]] $Items,

        [Parameter(Mandatory = $true)]
        [string] $SessionId,

        [Parameter(Mandatory = $true)]
        [string] $Context
    )

    $match = @(
        $Items |
            Where-Object {
                $null -ne $_ -and
                [string] $_.id -eq $SessionId
            }
    )

    if ($match.Count -lt 1) {
        Fail "La sesion temporal no aparecio en el filtro: $Context."
    }
}

function New-IsolatedAuthenticatedSession {
    param(
        [Parameter(Mandatory = $true)]
        [Microsoft.PowerShell.Commands.WebRequestSession] $SourceSession,

        [Parameter(Mandatory = $true)]
        [string] $BaseUrl
    )

    $isolated = New-Object Microsoft.PowerShell.Commands.WebRequestSession

    $sourceCookies = $SourceSession.Cookies.GetCookies($BaseUrl)

    foreach ($cookie in $sourceCookies) {
        $copy = New-Object System.Net.Cookie(
            $cookie.Name,
            $cookie.Value,
            $cookie.Path,
            $cookie.Domain
        )

        $copy.HttpOnly = $cookie.HttpOnly
        $copy.Secure = $cookie.Secure

        if ($cookie.Expires -ne [datetime]::MinValue) {
            $copy.Expires = $cookie.Expires
        }

        $isolated.Cookies.Add($copy)
    }

    return $isolated
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

$statePath = Join-Path $PSScriptRoot ".test-state\attendance.json"

if (-not (Test-Path $statePath)) {
    Fail "No existe el estado de Attendance: $statePath. Ejecute primero Create-AttendanceTestData.ps1."
}

$state = Get-Content `
    -Raw `
    -Path $statePath |
    ConvertFrom-Json

if ([string] $state.module -ne "attendance") {
    Fail "El archivo de estado no corresponde al modulo Attendance."
}

$campusId = [string] $state.campus.id
$schoolGroupId = [string] $state.school_group.id
$teachingAssignmentId = [string] $state.teaching_assignment.id
$teacherId = [string] $state.teacher.id
$sessionId = [string] $state.attendance_session.id
$heldOn = [string] $state.attendance_session.held_on
$startsAt = [string] $state.attendance_session.starts_at
$endsAt = [string] $state.attendance_session.ends_at
$notes = [string] $state.attendance_session.notes
$stateRecords = @($state.attendance_records.items)

if ([string]::IsNullOrWhiteSpace($campusId)) {
    Fail "El estado no contiene campus.id."
}

if ([string]::IsNullOrWhiteSpace($sessionId)) {
    Fail "El estado no contiene attendance_session.id."
}

if ($stateRecords.Count -lt 1) {
    Fail "El estado no contiene AttendanceRecords."
}

$headers = Clone-Headers -Headers $global:authHeaders
$headers["X-Campus-ID"] = $campusId

Write-Host "Fixture:"
Write-Host "  Campus:                 $campusId"
Write-Host "  Attendance Session:     $sessionId"
Write-Host "  Teaching Assignment:    $teachingAssignmentId"
Write-Host "  School Group:           $schoolGroupId"
Write-Host "  Records:                $($stateRecords.Count)"
Write-Host ""

Write-Host "1. GET individual de la sesion temporal..." -ForegroundColor Cyan

$showResponse = Invoke-Api `
    -Method "GET" `
    -Uri "$baseUrl/api/attendance-sessions/$sessionId" `
    -Headers $headers

$show = Get-ResponseData -Response $showResponse

if ([string] $show.id -ne $sessionId) {
    Fail "GET individual devolvio un UUID distinto."
}

if ([string] $show.status -ne "scheduled") {
    Fail "La sesion debe iniciar con status=scheduled."
}

if ([string] $show.notes -ne $notes) {
    Fail "La marca unica del fixture no coincide."
}

$showRecords = @($show.records)

if ($showRecords.Count -ne $stateRecords.Count) {
    Fail "GET individual devolvio una cantidad inesperada de records."
}

Pass "GET individual correcto; fixture intacto."

Write-Host ""
Write-Host "2. POST duplicado debe ser rechazado..." -ForegroundColor Cyan

$duplicateBody = @{
    school_group_id         = $schoolGroupId
    teaching_assignment_id = $teachingAssignmentId
    recorded_by_teacher_id = $teacherId
    held_on                 = $heldOn
    starts_at               = $startsAt
    ends_at                 = $endsAt
    type                    = "class"
    status                  = "scheduled"
    notes                   = "$notes-DUPLICATE"
}

$null = Invoke-ExpectHttpError `
    -Method "POST" `
    -Uri "$baseUrl/api/attendance-sessions" `
    -Headers $headers `
    -Body $duplicateBody `
    -ExpectedStatus 422

Pass "La definicion duplicada fue rechazada con HTTP 422."

Write-Host ""
Write-Host "3. PATCH scheduled -> open..." -ForegroundColor Cyan

$openResponse = Invoke-Api `
    -Method "PATCH" `
    -Uri "$baseUrl/api/attendance-sessions/$sessionId" `
    -Headers $headers `
    -Body @{
        status = "open"
    }

$openSession = Get-ResponseData -Response $openResponse

if ([string] $openSession.status -ne "open") {
    Fail "La sesion no cambio a status=open."
}

Pass "Transicion scheduled -> open correcta."

Write-Host ""
Write-Host "4. Cerrar con records pending debe fallar..." -ForegroundColor Cyan

$null = Invoke-ExpectHttpError `
    -Method "PATCH" `
    -Uri "$baseUrl/api/attendance-sessions/$sessionId" `
    -Headers $headers `
    -Body @{
        status = "closed"
    } `
    -ExpectedStatus 422

Pass "El backend impidio cerrar una sesion con pendientes."

Write-Host ""
Write-Host "5. Validacion: late con minutes_late=0 debe fallar..." -ForegroundColor Cyan

$firstEnrollmentId = [string] $stateRecords[0].enrollment_id

$null = Invoke-ExpectHttpError `
    -Method "PATCH" `
    -Uri "$baseUrl/api/attendance-sessions/$sessionId/records" `
    -Headers $headers `
    -Body @{
        records = @(
            @{
                enrollment_id = $firstEnrollmentId
                status = "late"
                minutes_late = 0
                notes = "INVALID-LATE-ZERO"
            }
        )
    } `
    -ExpectedStatus 422

Pass "late con 0 minutos fue rechazado."

Write-Host ""
Write-Host "6. Validacion: present con minutes_late>0 debe fallar..." -ForegroundColor Cyan

$null = Invoke-ExpectHttpError `
    -Method "PATCH" `
    -Uri "$baseUrl/api/attendance-sessions/$sessionId/records" `
    -Headers $headers `
    -Body @{
        records = @(
            @{
                enrollment_id = $firstEnrollmentId
                status = "present"
                minutes_late = 5
                notes = "INVALID-PRESENT-MINUTES"
            }
        )
    } `
    -ExpectedStatus 422

Pass "present con minutos de retraso fue rechazado."

Write-Host ""
Write-Host "7. Actualizando todos los AttendanceRecords..." -ForegroundColor Cyan

$statusPlan = @(
    "present",
    "absent",
    "late",
    "excused"
)

$recordUpdates = @()

for ($i = 0; $i -lt $stateRecords.Count; $i++) {
    $status = $statusPlan[$i % $statusPlan.Count]
    $minutesLate = 0

    if ($status -eq "late") {
        $minutesLate = 7
    }

    $recordUpdates += @{
        enrollment_id = [string] $stateRecords[$i].enrollment_id
        status = $status
        minutes_late = $minutesLate
        notes = "API-TEST-ATTENDANCE-RECORD-$i-$status"
    }
}

$recordsUpdateResponse = Invoke-Api `
    -Method "PATCH" `
    -Uri "$baseUrl/api/attendance-sessions/$sessionId/records" `
    -Headers $headers `
    -Body @{
        records = $recordUpdates
    }

$recordsUpdateSession = Get-ResponseData -Response $recordsUpdateResponse
$updatedRecords = @($recordsUpdateSession.records)

if ($updatedRecords.Count -ne $stateRecords.Count) {
    Fail "La actualizacion devolvio una cantidad inesperada de records."
}

$pendingAfterUpdate = @(
    $updatedRecords |
        Where-Object {
            [string] $_.status -eq "pending"
        }
)

if ($pendingAfterUpdate.Count -ne 0) {
    Fail "Quedaron AttendanceRecords en status=pending."
}

$lateRecords = @(
    $updatedRecords |
        Where-Object {
            [string] $_.status -eq "late"
        }
)

foreach ($lateRecord in $lateRecords) {
    if ([int] $lateRecord.minutes_late -lt 1) {
        Fail "Un record late no conserva minutes_late > 0."
    }
}

$nonLateWithMinutes = @(
    $updatedRecords |
        Where-Object {
            [string] $_.status -ne "late" -and
            [int] $_.minutes_late -ne 0
        }
)

if ($nonLateWithMinutes.Count -ne 0) {
    Fail "Un record no-late conserva minutes_late distinto de 0."
}

Pass "Todos los records fueron actualizados y no quedan pendientes."

Write-Host ""
Write-Host "8. Cerrando sesion sin pendientes..." -ForegroundColor Cyan

$closeResponse = Invoke-Api `
    -Method "PATCH" `
    -Uri "$baseUrl/api/attendance-sessions/$sessionId" `
    -Headers $headers `
    -Body @{
        status = "closed"
    }

$closedSession = Get-ResponseData -Response $closeResponse

if ([string] $closedSession.status -ne "closed") {
    Fail "La sesion no cambio a status=closed."
}

Pass "La sesion pudo cerrarse correctamente."

Write-Host ""
Write-Host "9. Sesion cerrada no debe permitir modificacion..." -ForegroundColor Cyan

$null = Invoke-ExpectHttpError `
    -Method "PATCH" `
    -Uri "$baseUrl/api/attendance-sessions/$sessionId" `
    -Headers $headers `
    -Body @{
        notes = "$notes-SHOULD-NOT-CHANGE"
    } `
    -ExpectedStatus 422

Pass "La sesion cerrada rechazo modificaciones."

Write-Host ""
Write-Host "10. Sesion cerrada no debe permitir modificar records..." -ForegroundColor Cyan

$null = Invoke-ExpectHttpError `
    -Method "PATCH" `
    -Uri "$baseUrl/api/attendance-sessions/$sessionId/records" `
    -Headers $headers `
    -Body @{
        records = @(
            @{
                enrollment_id = $firstEnrollmentId
                status = "present"
                minutes_late = 0
                notes = "SHOULD-NOT-CHANGE"
            }
        )
    } `
    -ExpectedStatus 422

Pass "Los records de una sesion cerrada quedaron protegidos."

Write-Host ""
Write-Host "11. Verificando filtros de index..." -ForegroundColor Cyan

$filterCases = @(
    @{
        Name = "school_group_id"
        Uri = "$baseUrl/api/attendance-sessions?per_page=100&school_group_id=$([System.Uri]::EscapeDataString($schoolGroupId))"
    },
    @{
        Name = "teaching_assignment_id"
        Uri = "$baseUrl/api/attendance-sessions?per_page=100&teaching_assignment_id=$([System.Uri]::EscapeDataString($teachingAssignmentId))"
    },
    @{
        Name = "recorded_by_teacher_id"
        Uri = "$baseUrl/api/attendance-sessions?per_page=100&recorded_by_teacher_id=$([System.Uri]::EscapeDataString($teacherId))"
    },
    @{
        Name = "type=class"
        Uri = "$baseUrl/api/attendance-sessions?per_page=100&type=class"
    },
    @{
        Name = "status=closed"
        Uri = "$baseUrl/api/attendance-sessions?per_page=100&status=closed"
    },
    @{
        Name = "date_from/date_to"
        Uri = "$baseUrl/api/attendance-sessions?per_page=100&date_from=$heldOn&date_to=$heldOn"
    },
    @{
        Name = "search por notes"
        Uri = "$baseUrl/api/attendance-sessions?per_page=100&search=$([System.Uri]::EscapeDataString($notes))"
    }
)

foreach ($case in $filterCases) {
    $response = Invoke-Api `
        -Method "GET" `
        -Uri $case.Uri `
        -Headers $headers

    $items = @(Get-CollectionData -Response $response)

    Assert-ContainsSession `
        -Items $items `
        -SessionId $sessionId `
        -Context ([string] $case.Name)

    Write-Host "   OK - $($case.Name)" -ForegroundColor Green
}

Pass "Todos los filtros principales localizaron el fixture."

Write-Host ""
Write-Host "12. GET final y conteos..." -ForegroundColor Cyan

$finalShowResponse = Invoke-Api `
    -Method "GET" `
    -Uri "$baseUrl/api/attendance-sessions/$sessionId" `
    -Headers $headers

$finalShow = Get-ResponseData -Response $finalShowResponse
$finalRecords = @($finalShow.records)

if ([string] $finalShow.status -ne "closed") {
    Fail "GET final no refleja status=closed."
}

if ($finalRecords.Count -ne $stateRecords.Count) {
    Fail "GET final devolvio una cantidad inesperada de records."
}

$finalPending = @(
    $finalRecords |
        Where-Object {
            [string] $_.status -eq "pending"
        }
)

if ($finalPending.Count -ne 0) {
    Fail "GET final detecto records pendientes."
}

if (
    $null -ne $finalShow.PSObject.Properties["records_count"] -and
    $null -ne $finalShow.records_count -and
    [int] $finalShow.records_count -ne $stateRecords.Count
) {
    Fail "records_count final no coincide con el fixture."
}

Pass "Estado final consistente: closed y sin pendientes."

Write-Host ""
Write-Host "13. Aislamiento: falta X-Campus-ID debe responder 422..." -ForegroundColor Cyan

$isolatedSession = New-IsolatedAuthenticatedSession `
    -SourceSession $global:webSession `
    -BaseUrl $baseUrl

$noCampusHeaders = Clone-Headers -Headers $global:authHeaders
Remove-HeaderIgnoreCase `
    -Headers $noCampusHeaders `
    -Name "X-Campus-ID"

$null = Invoke-ExpectHttpError `
    -Method "GET" `
    -Uri "$baseUrl/api/attendance-sessions/$sessionId" `
    -Headers $noCampusHeaders `
    -WebSession $isolatedSession `
    -ExpectedStatus 422

Pass "La ruta individual exige contexto de plantel."

Write-Host ""
Write-Host "14. Verificando que el estado siga disponible para cleanup..." -ForegroundColor Cyan

if (-not (Test-Path $statePath)) {
    Fail "El archivo de estado desaparecio antes del cleanup."
}

$stateCheck = Get-Content `
    -Raw `
    -Path $statePath |
    ConvertFrom-Json

if ([string] $stateCheck.attendance_session.id -ne $sessionId) {
    Fail "El estado ya no apunta a la sesion temporal exacta."
}

Pass "El estado permanece intacto para Cleanup-AttendanceTestData.ps1."

Write-Host ""
Write-Host "========================================"
Write-Host " RESULTADO: TEST ATTENDANCE API OK"
Write-Host "========================================"
Write-Host ""
Write-Host "Sesion temporal validada:"
Write-Host "  $sessionId"
Write-Host ""
Write-Host "Estado final del fixture:"
Write-Host "  status = closed"
Write-Host "  records = $($stateRecords.Count)"
Write-Host "  pending = 0"
Write-Host ""
Write-Host "No se eliminaron datos."
Write-Host "El fixture queda listo para Cleanup-AttendanceTestData.ps1."
Write-Host ""
