$ErrorActionPreference = "Stop"

$backendUrl = "http://127.0.0.1:8000"
$frontendUrl = "http://localhost:4200"

$global:webSession = New-Object `
    Microsoft.PowerShell.Commands.WebRequestSession

$global:baseHeaders = @{
    "Accept" = "application/json"
    "Origin" = $frontendUrl
    "Referer" = "$frontendUrl/"
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

        $stream = $response.GetResponseStream()
        $reader = New-Object System.IO.StreamReader($stream)
        $body = $reader.ReadToEnd()
        $reader.Dispose()

        return $body
    }
    catch {
        return $ErrorRecord.Exception.Message
    }
}

try {
    Write-Host "Solicitando cookie CSRF..." `
        -ForegroundColor Cyan

    Invoke-WebRequest `
        -Uri "$backendUrl/sanctum/csrf-cookie" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $global:baseHeaders |
        Out-Null

    $xsrfCookie = $global:webSession.Cookies.GetCookies(
        [Uri]$backendUrl
    ) |
        Where-Object {
            $_.Name -eq "XSRF-TOKEN"
        } |
        Select-Object -First 1

    if ($null -eq $xsrfCookie) {
        throw "Laravel no devolvio la cookie XSRF-TOKEN."
    }

    $xsrfToken = [Uri]::UnescapeDataString(
        $xsrfCookie.Value
    )

    $global:authHeaders = @{
        "Accept" = "application/json"
        "Origin" = $frontendUrl
        "Referer" = "$frontendUrl/"
        "X-XSRF-TOKEN" = $xsrfToken
    }

    $email = Read-Host "Correo"

    $securePassword = Read-Host `
        "Contrasena" `
        -AsSecureString

    $passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR(
        $securePassword
    )

    try {
        $plainPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR(
            $passwordPointer
        )
    }
    finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR(
            $passwordPointer
        )
    }

    $loginBody = @{
        email = $email
        password = $plainPassword
    } | ConvertTo-Json

    $plainPassword = $null

    Write-Host "Iniciando sesion..." `
        -ForegroundColor Cyan

    $loginResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/auth/login" `
        -Method Post `
        -WebSession $global:webSession `
        -Headers $global:authHeaders `
        -ContentType "application/json" `
        -Body $loginBody

    Write-Host $loginResponse.message `
        -ForegroundColor Green

    
    $xsrfCookie = $global:webSession.Cookies.GetCookies(
        [Uri]$backendUrl
    ) |
        Where-Object {
            $_.Name -eq "XSRF-TOKEN"
        } |
        Select-Object -First 1

    if ($null -eq $xsrfCookie) {
        throw "No existe XSRF-TOKEN despues del login."
    }

    $global:authHeaders["X-XSRF-TOKEN"] =
        [Uri]::UnescapeDataString($xsrfCookie.Value)

    Write-Host "Verificando usuario autenticado..." `
        -ForegroundColor Cyan

    $meResponse = Invoke-RestMethod `
        -Uri "$backendUrl/api/auth/me" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $global:authHeaders

    Write-Host ""
    Write-Host "Autenticacion verificada correctamente." `
        -ForegroundColor Green
    Write-Host "Usuario: $($meResponse.user.name)"
    Write-Host "Correo:  $($meResponse.user.email)"
    Write-Host ""
    Write-Host "La sesion permanece disponible en:" `
        -ForegroundColor Yellow
    Write-Host '  $global:webSession'
    Write-Host '  $global:baseHeaders'
    Write-Host '  $global:authHeaders'
}
catch {
    Write-Host ""
    Write-Host "La prueba de autenticacion fallo." `
        -ForegroundColor Red

    Write-Host (
        Get-ErrorResponseBody -ErrorRecord $_
    ) -ForegroundColor Red

    throw
}
