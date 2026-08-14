$ErrorActionPreference = "Stop"

$backendUrl = "http://127.0.0.1:8000"

$campusId = "019fd365-2df9-7dae-89a0-2ced47c13278"

$assessmentId = `
    "019fda73-343d-70e9-a059-a8bfb50c7b76"

$enrollmentId = `
    "019fda40-2076-714b-8b81-c3db3aeab9b7"

if (-not $global:webSession) {
    throw "No existe una sesion HTTP. Ejecuta primero: . .\scripts\Test-Auth.ps1"
}

if (-not $global:authHeaders) {
    throw "No existen encabezados de autenticacion. Ejecuta primero Test-Auth.ps1."
}

function Get-ErrorResponseBody {
    param(
        [Parameter(Mandatory = $true)]
        $ErrorRecord
    )

    try {
        $httpResponse = $ErrorRecord.Exception.Response

        if ($null -eq $httpResponse) {
            return $ErrorRecord.Exception.Message
        }

        $reader = New-Object System.IO.StreamReader(
            $httpResponse.GetResponseStream()
        )

        $body = $reader.ReadToEnd()
        $reader.Dispose()

        return $body
    }
    catch {
        return $ErrorRecord.Exception.Message
    }
}

$gradeHeaders = $global:authHeaders.Clone()
$gradeHeaders["X-Campus-ID"] = $campusId

$gradeId = $null

[decimal] $originalScore = 0
[string] $originalFeedback = ""
[string] $originalGradedAt = ""
[string] $originalStatus = ""
[string] $studentFullName = ""

$gradeWasModified = $false
$restoreSucceeded = $false
$testCompleted = $false

try {
    Write-Host "1. Comprobando sesion autenticada..."

    $currentUser = Invoke-RestMethod `
        -Uri "$backendUrl/api/auth/me" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $global:authHeaders

    Write-Host "   Usuario: $($currentUser.user.email)"

    Write-Host "2. Localizando calificacion..."

    $listResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/grades?assessment_id=$assessmentId&enrollment_id=$enrollmentId&per_page=10" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $gradeHeaders

    $grade = $listResponse.data |
        Select-Object -First 1

    if (-not $grade) {
        throw "No se encontro la calificacion esperada."
    }

    $gradeId = [string] $grade.id
    $originalScore = [decimal] $grade.score
    $originalFeedback = [string] $grade.feedback
    $originalGradedAt = [string] $grade.graded_at
    $originalStatus = [string] $grade.status
    $studentFullName = [string] $grade.enrollment.student.full_name

    Write-Host "   Calificacion ID: $gradeId"
    Write-Host "   Alumno: $studentFullName"
    Write-Host "   Evaluacion: $($grade.assessment.name)"
    Write-Host "   Puntuacion original: $originalScore"
    Write-Host "   Estado original: $originalStatus"

    Write-Host "3. Consultando calificacion..."

    $showResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/grades/$gradeId" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $gradeHeaders

    if ($showResponse.data.id -ne $gradeId) {
        throw "La consulta devolvio otra calificacion."
    }

    Write-Host "   Consulta: correcta."
    Write-Host "   Porcentaje: $($showResponse.data.percentage)%"

    Write-Host "4. Comprobando rechazo de duplicado..."

    $duplicateBody = @{
        assessment_id = $assessmentId
        enrollment_id = $enrollmentId
        score = 90
        feedback = "Intento duplicado de prueba."
        status = "graded"
    } | ConvertTo-Json -Depth 10

    $duplicateRejected = $false

    try {
        Invoke-RestMethod `
            -Uri "$backendUrl/api/grades" `
            -Method Post `
            -WebSession $global:webSession `
            -Headers $gradeHeaders `
            -ContentType "application/json" `
            -Body $duplicateBody |
            Out-Null
    }
    catch {
        if (
            $_.Exception.Response `
            -and [int] $_.Exception.Response.StatusCode -eq 422
        ) {
            $duplicateRejected = $true
        }
        else {
            throw
        }
    }

    if (-not $duplicateRejected) {
        throw "La API permitio una calificacion duplicada."
    }

    Write-Host "   Duplicado rechazado con 422."

    Write-Host "5. Actualizando temporalmente..."

    [decimal] $temporaryScore = 88.50

    $temporaryFeedback = `
        "Actualizacion temporal API $(Get-Date -Format 'yyyyMMddHHmmss')"

    $updateBody = @{
        score = [double] $temporaryScore
        feedback = $temporaryFeedback
        status = "graded"
    } | ConvertTo-Json -Depth 10

    $gradeWasModified = $true

    $updatedResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/grades/$gradeId" `
        -Method Patch `
        -WebSession $global:webSession `
        -Headers $gradeHeaders `
        -ContentType "application/json" `
        -Body $updateBody

    if (
        [decimal] $updatedResponse.data.score `
            -ne $temporaryScore
    ) {
        throw "La puntuacion temporal no fue guardada."
    }

    if (
        [string] $updatedResponse.data.feedback `
            -ne $temporaryFeedback
    ) {
        throw "La retroalimentacion temporal no fue guardada."
    }

    Write-Host "   Actualizacion temporal: correcta."

    Write-Host "6. Probando filtro por estado..."

    $statusResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/grades?status=graded&assessment_id=$assessmentId&per_page=100" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $gradeHeaders

    if (
        -not (
            $statusResponse.data |
                Where-Object {
                    $_.id -eq $gradeId
                }
        )
    ) {
        throw "El filtro por estado no devolvio la calificacion."
    }

    Write-Host "   Filtro por estado: correcto."

    Write-Host "7. Probando filtro por evaluacion..."

    $assessmentResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/grades?assessment_id=$assessmentId&per_page=100" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $gradeHeaders

    if (
        -not (
            $assessmentResponse.data |
                Where-Object {
                    $_.id -eq $gradeId
                }
        )
    ) {
        throw "El filtro por evaluacion no devolvio la calificacion."
    }

    Write-Host "   Filtro por evaluacion: correcto."

    Write-Host "8. Probando filtro por inscripcion..."

    $enrollmentResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/grades?enrollment_id=$enrollmentId&per_page=100" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $gradeHeaders

    if (
        -not (
            $enrollmentResponse.data |
                Where-Object {
                    $_.id -eq $gradeId
                }
        )
    ) {
        throw "El filtro por inscripcion no devolvio la calificacion."
    }

    Write-Host "   Filtro por inscripcion: correcto."

    Write-Host "9. Buscando por nombre completo..."

    $encodedSearch = [Uri]::EscapeDataString(
        $studentFullName
    )

    $searchResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/grades?search=$encodedSearch&per_page=100" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $gradeHeaders

    if (
        -not (
            $searchResponse.data |
                Where-Object {
                    $_.id -eq $gradeId
                }
        )
    ) {
        throw "La busqueda por nombre completo no devolvio la calificacion."
    }

    Write-Host "   Busqueda por nombre completo: correcta."

    $testCompleted = $true
}
catch {
    Write-Host ""
    Write-Host "LA PRUEBA DE CALIFICACIONES FALLO" `
        -ForegroundColor Red

    Write-Host (
        Get-ErrorResponseBody -ErrorRecord $_
    ) -ForegroundColor Red
}
finally {
    if ($gradeWasModified -and $gradeId) {
        Write-Host ""
        Write-Host "10. Restaurando valores originales..." `
            -ForegroundColor Yellow

        try {
            $restoreBody = @{
                score = [double] $originalScore
                feedback = $originalFeedback
                graded_at = $originalGradedAt
                status = $originalStatus
            } | ConvertTo-Json -Depth 10

            Invoke-RestMethod `
                -Uri "$backendUrl/api/grades/$gradeId" `
                -Method Patch `
                -WebSession $global:webSession `
                -Headers $gradeHeaders `
                -ContentType "application/json" `
                -Body $restoreBody |
                Out-Null

            $verificationResponse = Invoke-RestMethod `
                -Uri "$backendUrl/api/grades/$gradeId" `
                -Method Get `
                -WebSession $global:webSession `
                -Headers $gradeHeaders

            $restored = $verificationResponse.data

            if (
                [decimal] $restored.score `
                    -ne $originalScore
            ) {
                throw "La puntuacion original no fue restaurada."
            }

            if (
                [string] $restored.feedback `
                    -ne $originalFeedback
            ) {
                throw "La retroalimentacion original no fue restaurada."
            }

            if (
                [string] $restored.status `
                    -ne $originalStatus
            ) {
                throw "El estado original no fue restaurado."
            }

            $restoreSucceeded = $true

            Write-Host "   Valores originales restaurados." `
                -ForegroundColor Green
        }
        catch {
            Write-Host "RESTAURACION FALLIDA PARA $gradeId" `
                -ForegroundColor Red

            Write-Host (
                Get-ErrorResponseBody -ErrorRecord $_
            ) -ForegroundColor Red
        }
    }
}

if (-not $restoreSucceeded) {
    throw "La prueba no pudo confirmar la restauracion de la calificacion."
}

if (-not $testCompleted) {
    throw "La prueba fallo, aunque los datos originales fueron restaurados."
}

Write-Host ""
Write-Host "PRUEBA DE CALIFICACIONES COMPLETADA CORRECTAMENTE" `
    -ForegroundColor Green

Write-Host "Consultar:            OK"
Write-Host "Rechazar duplicado:   OK"
Write-Host "Actualizar:           OK"
Write-Host "Filtrar estado:       OK"
Write-Host "Filtrar evaluacion:   OK"
Write-Host "Filtrar inscripcion:  OK"
Write-Host "Buscar nombre:        OK"
Write-Host "Restaurar datos:      OK"
Write-Host "Trazabilidad:         OK"