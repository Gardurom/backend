$ErrorActionPreference = "Stop"

$backendUrl = "http://127.0.0.1:8000"

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path

$stateDirectory = Join-Path `
    $scriptRoot `
    ".test-state"

$stateFile = Join-Path `
    $stateDirectory `
    "subjects.json"

Write-Host ""
Write-Host "========================================"
Write-Host " CONTROL ESCOLAR - CREACION DE DATOS API"
Write-Host " MODULO: MATERIAS"
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
Ya existe un archivo de estado para Subjects:

$stateFile

No se creara otra materia temporal.

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

$subjectHeaders = $global:authHeaders.Clone()

$subjectHeaders["Accept"] = "application/json"
$subjectHeaders["X-Campus-ID"] = $campusId

$uniqueSuffix = Get-Date -Format "yyyyMMddHHmmssfff"

$subjectCode = "TEST-SUB-$uniqueSuffix"
$subjectName = "Materia API $uniqueSuffix"

$createBodyObject = @{
    campus_id = $campusId

    code = $subjectCode

    name = $subjectName

    description = "Materia temporal creada por pruebas de API."

    weekly_hours = 5

    is_active = $true
}

$createBody = $createBodyObject |
    ConvertTo-Json -Depth 10

$subjectId = $null

try {
    Write-Host "3. Creando materia temporal..."

    Remove-Variable createdResponse `
        -ErrorAction SilentlyContinue

    $createdResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/subjects" `
        -Method Post `
        -WebSession $global:webSession `
        -Headers $subjectHeaders `
        -ContentType "application/json" `
        -Body $createBody

    if (-not $createdResponse.data) {
        throw "La API no devolvio data."
    }

    $subject = $createdResponse.data

    $subjectId = [string]$subject.id

    if (
        [string]::IsNullOrWhiteSpace(
            $subjectId
        )
    ) {
        throw "La API no devolvio el UUID de la materia."
    }

    if (
        [string]$subject.campus_id `
            -ne $campusId
    ) {
        throw @"
El campus devuelto no coincide.

Esperado:
$campusId

Recibido:
$($subject.campus_id)
"@
    }

    if (
        [string]$subject.code `
            -ne $subjectCode
    ) {
        throw @"
El codigo devuelto no coincide.

Esperado:
$subjectCode

Recibido:
$($subject.code)
"@
    }

    if (
        [string]$subject.name `
            -ne $subjectName
    ) {
        throw @"
El nombre devuelto no coincide.

Esperado:
$subjectName

Recibido:
$($subject.name)
"@
    }

    Write-Host "   OK"
    Write-Host "   Subject ID: $subjectId"
    Write-Host "   Campus ID:  $campusId"
    Write-Host "   Codigo:     $subjectCode"
    Write-Host "   Nombre:     $subjectName"
    Write-Host ""

    Write-Host "4. Confirmando materia mediante GET..."

    Remove-Variable showResponse `
        -ErrorAction SilentlyContinue

    $showResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/subjects/$subjectId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $subjectHeaders

    if (-not $showResponse.data) {
        throw "La API no devolvio data al consultar la materia."
    }

    if (
        [string]$showResponse.data.id `
            -ne $subjectId
    ) {
        throw @"
La API devolvio una materia diferente.

Esperado:
$subjectId

Recibido:
$($showResponse.data.id)
"@
    }

    if (
        [string]$showResponse.data.campus_id `
            -ne $campusId
    ) {
        throw @"
La materia consultada pertenece a otro plantel.

Esperado:
$campusId

Recibido:
$($showResponse.data.campus_id)
"@
    }

    if (
        [string]$showResponse.data.code `
            -ne $subjectCode
    ) {
        throw @"
El codigo consultado no coincide.

Esperado:
$subjectCode

Recibido:
$($showResponse.data.code)
"@
    }

    if (
        [string]$showResponse.data.name `
            -ne $subjectName
    ) {
        throw @"
El nombre consultado no coincide.

Esperado:
$subjectName

Recibido:
$($showResponse.data.name)
"@
    }

    Write-Host "   OK"
    Write-Host ""

    Write-Host "5. Creando archivo de estado..."

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
        module = "subjects"

        created_at = (
            Get-Date
        ).ToString("o")

        backend_url = $backendUrl

        campus = [ordered]@{
            id = $campusId
            name = [string]$campus.name
        }

        subject = [ordered]@{
            id = $subjectId
            campus_id = $campusId
            code = $subjectCode
            name = $subjectName
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

    Write-Host "6. Verificando archivo de estado..."

    $savedStateJson = [System.IO.File]::ReadAllText(
        $stateFile
    )

    $savedState = $savedStateJson |
        ConvertFrom-Json

    if (
        [string]$savedState.subject.id `
            -ne $subjectId
    ) {
        throw "El UUID guardado en subjects.json no coincide."
    }

    if (
        [string]$savedState.subject.campus_id `
            -ne $campusId
    ) {
        throw "El campus guardado en subjects.json no coincide."
    }

    if (
        [string]$savedState.subject.code `
            -ne $subjectCode
    ) {
        throw "El codigo guardado no coincide."
    }

    if (
        [string]$savedState.subject.name `
            -ne $subjectName
    ) {
        throw "El nombre guardado no coincide."
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
        "FALLO LA CREACION DE DATOS DE SUBJECTS." `
        -ForegroundColor Red

    Write-Host $_.Exception.Message `
        -ForegroundColor Red

    if ($subjectId) {
        Write-Host ""
        Write-Host `
            "Intentando eliminar la materia incompleta..." `
            -ForegroundColor Yellow

        try {
            Invoke-RestMethod `
                -Uri "$backendUrl/api/subjects/$subjectId" `
                -Method Delete `
                -WebSession $global:webSession `
                -Headers $subjectHeaders |
                Out-Null

            Write-Host `
                "Materia temporal eliminada." `
                -ForegroundColor Yellow
        }
        catch {
            Write-Host `
                "No fue posible eliminar automaticamente la materia." `
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

    throw
}

Write-Host "========================================"
Write-Host " RESULTADO: CREATE SUBJECTS OK"
Write-Host "========================================"
Write-Host ""
Write-Host "Modulo:                  SUBJECTS"
Write-Host "Campus ID:               $campusId"
Write-Host "Subject ID:              $subjectId"
Write-Host "Codigo:                  $subjectCode"
Write-Host "Nombre:                  $subjectName"
Write-Host "Consulta GET:            OK"
Write-Host "Archivo de estado:       OK"
Write-Host ""
Write-Host "La materia NO fue eliminada."
Write-Host ""
Write-Host "Siguiente fase: Test-SubjectsApi.ps1"
Write-Host ""