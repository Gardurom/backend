$ErrorActionPreference = "Stop"

$backendUrl = "http://127.0.0.1:8000"

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path

$stateDirectory = Join-Path `
    $scriptRoot `
    ".test-state"

$stateFile = Join-Path `
    $stateDirectory `
    "teaching-assignments.json"

Write-Host ""
Write-Host "========================================"
Write-Host " CONTROL ESCOLAR - CREACION DE DATOS API"
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
No existen los encabezados autenticados.

Ejecuta primero:

. .\scripts\Test-Auth.ps1
"@
}

if (Test-Path -LiteralPath $stateFile) {
    throw @"
Ya existe un archivo de estado para TeachingAssignments:

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

if (
    [string]::IsNullOrWhiteSpace(
        [string]$currentUser.user.email
    )
) {
    throw "La API no devolvio el correo del usuario autenticado."
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

Write-Host "3. Obteniendo grupo activo..."

Remove-Variable groupsResponse `
    -ErrorAction SilentlyContinue

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

$schoolGroupId = [string]$schoolGroup.id

if (
    [string]::IsNullOrWhiteSpace(
        $schoolGroupId
    )
) {
    throw "El grupo seleccionado no contiene UUID."
}

Write-Host "   OK"
Write-Host "   Group ID: $schoolGroupId"
Write-Host "   Grupo: $($schoolGroup.grade_level) $($schoolGroup.section)"
Write-Host ""

Write-Host "4. Obteniendo profesor activo..."

Remove-Variable teachersResponse `
    -ErrorAction SilentlyContinue

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

$teacherId = [string]$teacher.id

if (
    [string]::IsNullOrWhiteSpace(
        $teacherId
    )
) {
    throw "El profesor seleccionado no contiene UUID."
}

Write-Host "   OK"
Write-Host "   Teacher ID: $teacherId"
Write-Host "   Profesor: $($teacher.person.full_name)"
Write-Host ""

$uniqueSuffix = Get-Date -Format "yyyyMMddHHmmssfff"

$subjectCode = "TEST-TA-$uniqueSuffix"
$subjectName = "Materia Asignacion $uniqueSuffix"

$subjectId = $null
$assignmentId = $null
$creationSucceeded = $false

try {
    Write-Host "5. Creando materia temporal..."

    $subjectBodyObject = @{
        campus_id = $campusId

        code = $subjectCode

        name = $subjectName

        description = "Materia temporal para pruebas de asignaciones docentes."

        weekly_hours = 3.5

        is_active = $true
    }

    $subjectBody = $subjectBodyObject |
        ConvertTo-Json -Depth 10

    Remove-Variable subjectResponse `
        -ErrorAction SilentlyContinue

    $subjectResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/subjects" `
        -Method Post `
        -WebSession $global:webSession `
        -Headers $campusHeaders `
        -ContentType "application/json" `
        -Body $subjectBody

    if (-not $subjectResponse.data) {
        throw "La API no devolvio data para la materia."
    }

    $subjectId = [string]$subjectResponse.data.id

    if (
        [string]::IsNullOrWhiteSpace(
            $subjectId
        )
    ) {
        throw "La API no devolvio el UUID de la materia temporal."
    }

    if (
        [string]$subjectResponse.data.campus_id `
            -ne $campusId
    ) {
        throw "La materia temporal pertenece a otro plantel."
    }

    if (
        [string]$subjectResponse.data.code `
            -ne $subjectCode
    ) {
        throw "El codigo devuelto para la materia no coincide."
    }

    Write-Host "   OK"
    Write-Host "   Subject ID: $subjectId"
    Write-Host "   Codigo:     $subjectCode"
    Write-Host ""

    Write-Host "6. Creando asignacion docente temporal..."

    $startsOn = (Get-Date).ToString(
        "yyyy-MM-dd"
    )

    $endsOn = (Get-Date).AddDays(30).ToString(
        "yyyy-MM-dd"
    )

    $assignmentBodyObject = @{
        school_group_id = $schoolGroupId

        subject_id = $subjectId

        teacher_id = $teacherId

        starts_on = $startsOn

        ends_on = $endsOn

        status = "active"
    }

    $assignmentBody = $assignmentBodyObject |
        ConvertTo-Json -Depth 10

    Remove-Variable createdResponse `
        -ErrorAction SilentlyContinue

    $createdResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/teaching-assignments" `
        -Method Post `
        -WebSession $global:webSession `
        -Headers $campusHeaders `
        -ContentType "application/json" `
        -Body $assignmentBody

    if (-not $createdResponse.data) {
        throw "La API no devolvio data para la asignacion."
    }

    $assignment = $createdResponse.data

    $assignmentId = [string]$assignment.id

    if (
        [string]::IsNullOrWhiteSpace(
            $assignmentId
        )
    ) {
        throw "La API no devolvio el UUID de la asignacion."
    }

    if (
        [string]$assignment.school_group_id `
            -ne $schoolGroupId
    ) {
        throw "La asignacion devolvio un grupo incorrecto."
    }

    if (
        [string]$assignment.subject_id `
            -ne $subjectId
    ) {
        throw "La asignacion devolvio una materia incorrecta."
    }

    if (
        [string]$assignment.teacher_id `
            -ne $teacherId
    ) {
        throw "La asignacion devolvio un profesor incorrecto."
    }

    if (
        [string]$assignment.status `
            -ne "active"
    ) {
        throw "La asignacion no fue creada con estado active."
    }

    Write-Host "   OK"
    Write-Host "   Assignment ID: $assignmentId"
    Write-Host "   Group ID:      $schoolGroupId"
    Write-Host "   Subject ID:    $subjectId"
    Write-Host "   Teacher ID:    $teacherId"
    Write-Host "   Inicio:        $startsOn"
    Write-Host "   Fin:           $endsOn"
    Write-Host "   Estado:        active"
    Write-Host ""

    Write-Host "7. Confirmando asignacion mediante GET..."

    Remove-Variable showResponse `
        -ErrorAction SilentlyContinue

    $showResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/teaching-assignments/$assignmentId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $campusHeaders

    if (-not $showResponse.data) {
        throw "La API no devolvio la asignacion consultada."
    }

    if (
        [string]$showResponse.data.id `
            -ne $assignmentId
    ) {
        throw "El GET devolvio una asignacion diferente."
    }

    if (
        [string]$showResponse.data.school_group_id `
            -ne $schoolGroupId
    ) {
        throw "El grupo de la asignacion consultada no coincide."
    }

    if (
        [string]$showResponse.data.subject_id `
            -ne $subjectId
    ) {
        throw "La materia de la asignacion consultada no coincide."
    }

    if (
        [string]$showResponse.data.teacher_id `
            -ne $teacherId
    ) {
        throw "El profesor de la asignacion consultada no coincide."
    }

    if (
        [string]$showResponse.data.status `
            -ne "active"
    ) {
        throw "El estado consultado no coincide."
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
        module = "teaching-assignments"

        created_at = (
            Get-Date
        ).ToString("o")

        backend_url = $backendUrl

        campus = [ordered]@{
            id = $campusId
            name = [string]$campus.name
        }

        dependencies = [ordered]@{
            school_group = [ordered]@{
                id = $schoolGroupId
                grade_level = [string]$schoolGroup.grade_level
                section = [string]$schoolGroup.section
                created_by_api_test = $false
            }

            teacher = [ordered]@{
                id = $teacherId
                employee_number = [string]$teacher.employee_number
                created_by_api_test = $false
            }

            subject = [ordered]@{
                id = $subjectId
                code = $subjectCode
                name = $subjectName
                created_by_api_test = $true
            }
        }

        teaching_assignment = [ordered]@{
            id = $assignmentId
            school_group_id = $schoolGroupId
            subject_id = $subjectId
            teacher_id = $teacherId
            starts_on = $startsOn
            ends_on = $endsOn
            status = "active"
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
        throw "No fue posible crear teaching-assignments.json."
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
        [string]$savedState.teaching_assignment.id `
            -ne $assignmentId
    ) {
        throw "El Assignment ID guardado no coincide."
    }

    if (
        [string]$savedState.dependencies.subject.id `
            -ne $subjectId
    ) {
        throw "El Subject ID guardado no coincide."
    }

    if (
        [string]$savedState.dependencies.school_group.id `
            -ne $schoolGroupId
    ) {
        throw "El Group ID guardado no coincide."
    }

    if (
        [string]$savedState.dependencies.teacher.id `
            -ne $teacherId
    ) {
        throw "El Teacher ID guardado no coincide."
    }

    if (
        $savedState.dependencies.subject.created_by_api_test `
            -ne $true
    ) {
        throw "La materia temporal no esta marcada correctamente."
    }

    if (
        $savedState.dependencies.school_group.created_by_api_test `
            -ne $false
    ) {
        throw "El grupo existente esta marcado incorrectamente."
    }

    if (
        $savedState.dependencies.teacher.created_by_api_test `
            -ne $false
    ) {
        throw "El profesor existente esta marcado incorrectamente."
    }

    if (
        $savedState.test.created_by_api_test `
            -ne $true
    ) {
        throw "La asignacion no esta marcada como dato temporal."
    }

    Write-Host "   OK"
    Write-Host ""

    $creationSucceeded = $true
}
catch {
    Write-Host ""
    Write-Host `
        "FALLO LA CREACION DE DATOS DE TEACHING ASSIGNMENTS." `
        -ForegroundColor Red

    Write-Host (
        Get-ErrorResponseBody `
            -ErrorRecord $_
    ) -ForegroundColor Red
}
finally {
    if (-not $creationSucceeded) {
        if ($assignmentId) {
            Write-Host ""
            Write-Host `
                "Intentando eliminar la asignacion incompleta..." `
                -ForegroundColor Yellow

            try {
                Invoke-RestMethod `
                    -Uri "$backendUrl/api/teaching-assignments/$assignmentId" `
                    -Method Delete `
                    -WebSession $global:webSession `
                    -Headers $campusHeaders |
                    Out-Null

                Write-Host `
                    "Asignacion temporal eliminada." `
                    -ForegroundColor Yellow
            }
            catch {
                Write-Host `
                    "No fue posible eliminar la asignacion temporal." `
                    -ForegroundColor Red

                Write-Host `
                    "Assignment ID: $assignmentId" `
                    -ForegroundColor Red
            }
        }

        if ($subjectId) {
            Write-Host ""
            Write-Host `
                "Intentando eliminar la materia temporal..." `
                -ForegroundColor Yellow

            try {
                Invoke-RestMethod `
                    -Uri "$backendUrl/api/subjects/$subjectId" `
                    -Method Delete `
                    -WebSession $global:webSession `
                    -Headers $campusHeaders |
                    Out-Null

                Write-Host `
                    "Materia temporal eliminada." `
                    -ForegroundColor Yellow
            }
            catch {
                Write-Host `
                    "No fue posible eliminar la materia temporal." `
                    -ForegroundColor Red

                Write-Host `
                    "Subject ID: $subjectId" `
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
    throw "No fue posible preparar los datos de TeachingAssignments."
}

Write-Host "========================================"
Write-Host " RESULTADO: CREATE TEACHING ASSIGNMENTS OK" `
    -ForegroundColor Green
Write-Host "========================================"
Write-Host ""
Write-Host "Campus ID:               $campusId"
Write-Host "Group ID existente:      $schoolGroupId"
Write-Host "Teacher ID existente:    $teacherId"
Write-Host "Subject ID temporal:     $subjectId"
Write-Host "Assignment ID temporal:  $assignmentId"
Write-Host "Consulta GET:            OK"
Write-Host "Archivo de estado:       OK"
Write-Host ""
Write-Host "La asignacion NO fue eliminada."
Write-Host "La materia temporal NO fue eliminada."
Write-Host "El grupo existente NO fue modificado."
Write-Host "El profesor existente NO fue modificado."
Write-Host ""
Write-Host "Siguiente fase: Test-TeachingAssignmentsApi.ps1"
Write-Host ""