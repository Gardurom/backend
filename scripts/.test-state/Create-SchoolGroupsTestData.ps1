$ErrorActionPreference = "Stop"

$backendUrl = "http://127.0.0.1:8000"

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path

$stateDirectory = Join-Path `
    $scriptRoot `
    ".test-state"

$stateFile = Join-Path `
    $stateDirectory `
    "school-groups.json"

Write-Host ""
Write-Host "========================================"
Write-Host " CONTROL ESCOLAR - CREACION DE DATOS API"
Write-Host " MODULO: GRUPOS ESCOLARES"
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
Ya existe un archivo de estado para SchoolGroups:

$stateFile

No se creara otro grupo temporal.

Ejecuta primero el cleanup correspondiente
o revisa el estado existente.
"@
}

Write-Host "1. Verificando sesion autenticada..."

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

$campusResponse = Invoke-RestMethod `
    -Uri "$backendUrl/api/campuses?is_active=1&per_page=20" `
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

Write-Host "3. Buscando ciclo escolar del plantel..."

$tinkerCommand = @"
echo App\Models\SchoolCycle::query()
    ->where('campus_id', '$campusId')
    ->orderByDesc('is_current')
    ->orderByDesc('starts_on')
    ->value('id');
"@

$schoolCycleOutput = & php artisan tinker `
    --execute="$tinkerCommand" 2>&1

if ($LASTEXITCODE -ne 0) {
    throw @"
No fue posible consultar el ciclo escolar.

Resultado:
$schoolCycleOutput
"@
}

$schoolCycleId = (
    $schoolCycleOutput |
        Out-String
).Trim()

if (
    [string]::IsNullOrWhiteSpace(
        $schoolCycleId
    ) `
    -or $schoolCycleId -notmatch `
        '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$'
) {
    throw @"
No existe un ciclo escolar valido para el plantel seleccionado.

Resultado:
$schoolCycleId
"@
}

Write-Host "   OK"
Write-Host "   Ciclo escolar ID: $schoolCycleId"
Write-Host ""

$groupHeaders = $global:authHeaders.Clone()

$groupHeaders["Accept"] = "application/json"
$groupHeaders["X-Campus-ID"] = $campusId

$uniqueSuffix = Get-Date -Format "yyyyMMddHHmmssfff"

$gradeLevel = "Prueba API $uniqueSuffix"

$section = (
    "T" +
    (Get-Date).ToString("HHmmssfff")
)

$createBodyObject = @{
    school_cycle_id = $schoolCycleId

    grade_level = $gradeLevel

    section = $section

    shift = "evening"

    capacity = 25

    classroom = "Aula temporal"

    is_active = $true
}

$createBody = $createBodyObject |
    ConvertTo-Json -Depth 10

$schoolGroupId = $null

try {
    Write-Host "4. Creando grupo escolar temporal..."

    $createdResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/groups" `
        -Method Post `
        -WebSession $global:webSession `
        -Headers $groupHeaders `
        -ContentType "application/json" `
        -Body $createBody

    if (-not $createdResponse.data) {
        throw "La API no devolvio data."
    }

    $schoolGroup = $createdResponse.data

    $schoolGroupId = [string]$schoolGroup.id

    if (
        [string]::IsNullOrWhiteSpace(
            $schoolGroupId
        )
    ) {
        throw "La API no devolvio el UUID del grupo."
    }

    if (
        [string]$schoolGroup.school_cycle_id `
            -ne $schoolCycleId
    ) {
        throw @"
El ciclo escolar devuelto no coincide.

Esperado:
$schoolCycleId

Recibido:
$($schoolGroup.school_cycle_id)
"@
    }

    if (
        [string]$schoolGroup.grade_level `
            -ne $gradeLevel
    ) {
        throw @"
El grado devuelto no coincide.

Esperado:
$gradeLevel

Recibido:
$($schoolGroup.grade_level)
"@
    }

    if (
        [string]$schoolGroup.section `
            -ne $section
    ) {
        throw @"
La seccion devuelta no coincide.

Esperado:
$section

Recibido:
$($schoolGroup.section)
"@
    }

    Write-Host "   OK"
    Write-Host "   Group ID: $schoolGroupId"
    Write-Host "   Grado:    $gradeLevel"
    Write-Host "   Seccion:  $section"
    Write-Host ""

    Write-Host "5. Confirmando grupo mediante GET..."

    $showResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/groups/$schoolGroupId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $groupHeaders

    if (-not $showResponse.data) {
        throw "La API no devolvio data al consultar el grupo."
    }

    if (
        [string]$showResponse.data.id `
            -ne $schoolGroupId
    ) {
        throw @"
La API devolvio un grupo diferente.

Esperado:
$schoolGroupId

Recibido:
$($showResponse.data.id)
"@
    }

    if (
        [string]$showResponse.data.school_cycle_id `
            -ne $schoolCycleId
    ) {
        throw @"
El grupo consultado pertenece a otro ciclo escolar.
"@
    }

    if (
        [string]$showResponse.data.grade_level `
            -ne $gradeLevel
    ) {
        throw @"
El grado consultado no coincide.
"@
    }

    if (
        [string]$showResponse.data.section `
            -ne $section
    ) {
        throw @"
La seccion consultada no coincide.
"@
    }

    Write-Host "   OK"
    Write-Host ""

    Write-Host "6. Creando archivo de estado..."

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
        module = "school-groups"

        created_at = (
            Get-Date
        ).ToString("o")

        backend_url = $backendUrl

        campus = [ordered]@{
            id = $campusId
            name = [string]$campus.name
        }

        school_cycle = [ordered]@{
            id = $schoolCycleId
        }

        group = [ordered]@{
            id = $schoolGroupId
            grade_level = $gradeLevel
            section = $section
        }

        test = [ordered]@{
            suffix = $uniqueSuffix
            created_by_api_test = $true
        }
    }

    $stateJson = $stateObject |
        ConvertTo-Json -Depth 10

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
        throw @"
No fue posible crear:

$stateFile
"@
    }

    Write-Host "   OK"
    Write-Host "   Estado: $stateFile"
    Write-Host ""

    Write-Host "7. Verificando archivo de estado..."

    $savedStateJson = [System.IO.File]::ReadAllText(
        $stateFile
    )

    $savedState = $savedStateJson |
        ConvertFrom-Json

    if (
        [string]$savedState.group.id `
            -ne $schoolGroupId
    ) {
        throw @"
El UUID guardado en school-groups.json no coincide.
"@
    }

    if (
        [string]$savedState.group.grade_level `
            -ne $gradeLevel
    ) {
        throw @"
El grado guardado no coincide.
"@
    }

    if (
        [string]$savedState.group.section `
            -ne $section
    ) {
        throw @"
La seccion guardada no coincide.
"@
    }

    if (
        $savedState.test.created_by_api_test `
            -ne $true
    ) {
        throw @"
El archivo de estado no contiene:

created_by_api_test = true
"@
    }

    Write-Host "   OK"
    Write-Host ""
}
catch {
    Write-Host ""
    Write-Host `
        "FALLO LA CREACION DE DATOS DE SCHOOL GROUPS." `
        -ForegroundColor Red

    Write-Host $_.Exception.Message `
        -ForegroundColor Red

    if ($schoolGroupId) {
        Write-Host ""
        Write-Host `
            "Intentando eliminar el grupo incompleto..." `
            -ForegroundColor Yellow

        try {
            Invoke-RestMethod `
                -Uri "$backendUrl/api/groups/$schoolGroupId" `
                -Method Delete `
                -WebSession $global:webSession `
                -Headers $groupHeaders |
                Out-Null

            Write-Host `
                "Grupo temporal eliminado." `
                -ForegroundColor Yellow
        }
        catch {
            Write-Host `
                "No fue posible eliminar automaticamente el grupo." `
                -ForegroundColor Red

            Write-Host `
                "Group ID: $schoolGroupId" `
                -ForegroundColor Red
        }
    }

    if (Test-Path -LiteralPath $stateFile) {
        Remove-Item `
            -LiteralPath $stateFile `
            -Force
    }

    throw
}

Write-Host "========================================"
Write-Host " RESULTADO: CREATE SCHOOL GROUPS OK"
Write-Host "========================================"
Write-Host ""
Write-Host "Modulo:                  SCHOOL GROUPS"
Write-Host "Campus ID:               $campusId"
Write-Host "School Cycle ID:         $schoolCycleId"
Write-Host "Group ID:                $schoolGroupId"
Write-Host "Grado:                   $gradeLevel"
Write-Host "Seccion:                 $section"
Write-Host "Consulta GET:            OK"
Write-Host "Archivo de estado:       OK"
Write-Host ""
Write-Host "El grupo NO fue eliminado."
Write-Host ""
Write-Host (
    "Siguiente fase: Test-SchoolGroupsApi.ps1"
)
Write-Host ""