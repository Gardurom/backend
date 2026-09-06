#requires -Version 5.1

param(
    [ValidateSet(
        "All",
        "Students",
        "Teachers",
        "SchoolGroups",
        "Subjects",
        "TeachingAssignments",
        "Assessments",
        "Grades",
        "Attendance"
    )]
    [string] $Module = "All",

    [switch] $PreflightOnly
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Write-Title {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Text
    )

    Write-Host ""
    Write-Host "========================================"
    Write-Host " $Text"
    Write-Host "========================================"
    Write-Host ""
}

function Fail {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Message
    )

    throw $Message
}

function Resolve-ScriptPath {
    param(
        [Parameter(Mandatory = $true)]
        [string] $ScriptsRoot,

        [Parameter(Mandatory = $true)]
        [string] $FileName
    )

    return Join-Path $ScriptsRoot $FileName
}

function Assert-FileExists {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Path,

        [Parameter(Mandatory = $true)]
        [string] $Description
    )

    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        Fail "Falta $Description`: $Path"
    }
}

function Assert-NoStaleState {
    param(
        [Parameter(Mandatory = $true)]
        [string] $StatePath,

        [Parameter(Mandatory = $true)]
        [string] $ModuleName
    )

    if (Test-Path -LiteralPath $StatePath -PathType Leaf) {
        Fail @"
Existe un estado pendiente para ${ModuleName}:
$StatePath

No se iniciara una nueva corrida para evitar duplicar o mezclar fixtures.
Revise primero el modulo pendiente y complete su cleanup.
"@
    }
}

function Invoke-TestScript {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Path,

        [Parameter(Mandatory = $true)]
        [string] $ModuleName,

        [Parameter(Mandatory = $true)]
        [ValidateSet("CREATE", "TEST", "CLEANUP")]
        [string] $Phase
    )

    Write-Host ""
    Write-Host "----------------------------------------"
    Write-Host " $ModuleName - $Phase"
    Write-Host "----------------------------------------"
    Write-Host ""

    try {
        & $Path
    }
    catch {
        Write-Host ""
        Write-Host "========================================" -ForegroundColor Red
        Write-Host " SUITE DETENIDA" -ForegroundColor Red
        Write-Host "========================================" -ForegroundColor Red
        Write-Host ""
        Write-Host "Modulo: $ModuleName" -ForegroundColor Yellow
        Write-Host "Fase:   $Phase" -ForegroundColor Yellow
        Write-Host ""
        Write-Host "Error:" -ForegroundColor Yellow
        Write-Host $_.Exception.Message -ForegroundColor Red
        Write-Host ""
        Write-Host "NO vuelva a ejecutar Run-AllApiTests.ps1 todavia." -ForegroundColor Yellow
        Write-Host "Revise el estado exacto del fixture antes de repetir CREATE/TEST/CLEANUP." -ForegroundColor Yellow
        Write-Host ""

        throw
    }
}

$backendRoot = Split-Path -Parent $PSScriptRoot
$scriptsRoot = $PSScriptRoot
$stateRoot = Join-Path $scriptsRoot ".test-state"
$authScript = Join-Path $scriptsRoot "Test-Auth.ps1"

$manifest = @(
    [pscustomobject]@{
        Name = "Students"
        StateFile = "students.json"
        Create = "Create-StudentsTestData.ps1"
        Test = "Test-StudentsApi.ps1"
        Cleanup = "Cleanup-StudentsTestData.ps1"
    },
    [pscustomobject]@{
        Name = "Teachers"
        StateFile = "teachers.json"
        Create = "Create-TeachersTestData.ps1"
        Test = "Test-TeachersApi.ps1"
        Cleanup = "Cleanup-TeachersTestData.ps1"
    },
    [pscustomobject]@{
        Name = "SchoolGroups"
        StateFile = "school-groups.json"
        Create = "Create-SchoolGroupsTestData.ps1"
        Test = "Test-SchoolGroupsApi.ps1"
        Cleanup = "Cleanup-SchoolGroupsTestData.ps1"
    },
    [pscustomobject]@{
        Name = "Subjects"
        StateFile = "subjects.json"
        Create = "Create-SubjectsTestData.ps1"
        Test = "Test-SubjectsApi.ps1"
        Cleanup = "Cleanup-SubjectsTestData.ps1"
    },
    [pscustomobject]@{
        Name = "TeachingAssignments"
        StateFile = "teaching-assignments.json"
        Create = "Create-TeachingAssignmentsTestData.ps1"
        Test = "Test-TeachingAssignmentsApi.ps1"
        Cleanup = "Cleanup-TeachingAssignmentsTestData.ps1"
    },
    [pscustomobject]@{
        Name = "Assessments"
        StateFile = "assessments.json"
        Create = "Create-AssessmentsTestData.ps1"
        Test = "Test-AssessmentsApi.ps1"
        Cleanup = "Cleanup-AssessmentsTestData.ps1"
    },
    [pscustomobject]@{
        Name = "Grades"
        StateFile = "grades.json"
        Create = "Create-GradesTestData.ps1"
        Test = "Test-GradesApi.ps1"
        Cleanup = "Cleanup-GradesTestData.ps1"
    },
    [pscustomobject]@{
        Name = "Attendance"
        StateFile = "attendance.json"
        Create = "Create-AttendanceTestData.ps1"
        Test = "Test-AttendanceApi.ps1"
        Cleanup = "Cleanup-AttendanceTestData.ps1"
    }
)

if ($Module -eq "All") {
    $selectedModules = @($manifest)
}
else {
    $selectedModules = @(
        $manifest |
            Where-Object {
                $_.Name -eq $Module
            }
    )
}

if ($selectedModules.Count -eq 0) {
    Fail "No se encontro el modulo solicitado: $Module"
}

Write-Title "CONTROL ESCOLAR - SUITE API"

Write-Host "Backend:"
Write-Host "  $backendRoot"
Write-Host ""
Write-Host "Seleccion:"
Write-Host "  $Module"
Write-Host ""
Write-Host "Modulos incluidos:"

foreach ($item in $selectedModules) {
    Write-Host "  - $($item.Name)"
}

Write-Host ""
Write-Host "1. PREFLIGHT" -ForegroundColor Cyan

Assert-FileExists `
    -Path (Join-Path $backendRoot "artisan") `
    -Description "artisan"

Assert-FileExists `
    -Path $authScript `
    -Description "script de autenticacion"

$artisanVersionOutput = & php artisan --version 2>&1
$artisanVersionExitCode = $LASTEXITCODE
$artisanVersionText = ($artisanVersionOutput | Out-String).Trim()

if ($artisanVersionExitCode -ne 0) {
    Fail "php artisan --version fallo. Salida: $artisanVersionText"
}

Write-Host "   OK - $artisanVersionText" -ForegroundColor Green

foreach ($item in $selectedModules) {
    $createPath = Resolve-ScriptPath `
        -ScriptsRoot $scriptsRoot `
        -FileName $item.Create

    $testPath = Resolve-ScriptPath `
        -ScriptsRoot $scriptsRoot `
        -FileName $item.Test

    $cleanupPath = Resolve-ScriptPath `
        -ScriptsRoot $scriptsRoot `
        -FileName $item.Cleanup

    Assert-FileExists `
        -Path $createPath `
        -Description "$($item.Name) CREATE"

    Assert-FileExists `
        -Path $testPath `
        -Description "$($item.Name) TEST"

    Assert-FileExists `
        -Path $cleanupPath `
        -Description "$($item.Name) CLEANUP"

    $statePath = Join-Path $stateRoot $item.StateFile

    Assert-NoStaleState `
        -StatePath $statePath `
        -ModuleName $item.Name
}

Write-Host "   OK - Scripts requeridos presentes." -ForegroundColor Green
Write-Host "   OK - No hay estados pendientes para la seleccion." -ForegroundColor Green

if ($PreflightOnly) {
    Write-Host ""
    Write-Host "========================================"
    Write-Host " RESULTADO: PREFLIGHT OK"
    Write-Host "========================================"
    Write-Host ""
    Write-Host "No se crearon ni modificaron fixtures."
    Write-Host ""

    exit 0
}

Write-Host ""
Write-Host "2. AUTENTICACION" -ForegroundColor Cyan
Write-Host ""
Write-Host "Se ejecutara Test-Auth.ps1 una sola vez."
Write-Host "Ingrese la contrasena cuando el script la solicite."
Write-Host ""

try {
    . $authScript
}
catch {
    Fail "Fallo Test-Auth.ps1: $($_.Exception.Message)"
}

if (
    (-not (Test-Path variable:global:webSession)) -or
    ($null -eq $global:webSession)
) {
    Fail "Test-Auth.ps1 termino sin crear `$global:webSession."
}

if (
    (-not (Test-Path variable:global:authHeaders)) -or
    ($null -eq $global:authHeaders)
) {
    Fail "Test-Auth.ps1 termino sin crear `$global:authHeaders."
}

Write-Host ""
Write-Host "   OK - Sesion autenticada disponible para la suite." -ForegroundColor Green

$completedModules = New-Object System.Collections.Generic.List[string]

foreach ($item in $selectedModules) {
    $createPath = Resolve-ScriptPath `
        -ScriptsRoot $scriptsRoot `
        -FileName $item.Create

    $testPath = Resolve-ScriptPath `
        -ScriptsRoot $scriptsRoot `
        -FileName $item.Test

    $cleanupPath = Resolve-ScriptPath `
        -ScriptsRoot $scriptsRoot `
        -FileName $item.Cleanup

    Invoke-TestScript `
        -Path $createPath `
        -ModuleName $item.Name `
        -Phase "CREATE"

    Invoke-TestScript `
        -Path $testPath `
        -ModuleName $item.Name `
        -Phase "TEST"

    Invoke-TestScript `
        -Path $cleanupPath `
        -ModuleName $item.Name `
        -Phase "CLEANUP"

    $statePath = Join-Path $stateRoot $item.StateFile

    if (Test-Path -LiteralPath $statePath -PathType Leaf) {
        Fail "El modulo $($item.Name) termino, pero su estado sigue presente: $statePath"
    }

    $completedModules.Add($item.Name)

    Write-Host ""
    Write-Host "   MODULO CERRADO: $($item.Name)" -ForegroundColor Green
}

Write-Host ""
Write-Host "========================================"
Write-Host " RESULTADO: SUITE API COMPLETA OK"
Write-Host "========================================"
Write-Host ""
Write-Host "Modulos completados:"

foreach ($name in $completedModules) {
    Write-Host "  - $name"
}

Write-Host ""
Write-Host "Todos los ciclos CREATE -> TEST -> CLEANUP finalizaron correctamente."
Write-Host "No quedaron archivos de estado de los modulos ejecutados."
Write-Host ""
