$ErrorActionPreference = "Stop"

$backendUrl = "http://127.0.0.1:8000"
$frontendUrl = "http://localhost:4200"

$global:webSession =
    New-Object Microsoft.PowerShell.Commands.WebRequestSession

$global:baseHeaders = @{
    Accept = "application/json"
    Origin = $frontendUrl
    Referer = "$frontendUrl/"
}

Write-Host "Solicitando cookie CSRF..."

Invoke-WebRequest `
    -Uri "$backendUrl/sanctum/csrf-cookie" `
    -Method Get `
    -WebSession $global:webSession `
    -Headers $global:baseHeaders |
    Out-Null

$cookies = $global:webSession.Cookies.GetCookies(
    [Uri]$backendUrl
)

$xsrfCookie = $cookies |
    Where-Object { $_.Name -eq "XSRF-TOKEN" } |
    Select-Object -First 1

if (-not $xsrfCookie) {
    throw "Laravel no devolvió la cookie XSRF-TOKEN."
}

$xsrfToken = [Uri]::UnescapeDataString(
    $xsrfCookie.Value
)

$global:authHeaders = @{
    Accept = "application/json"
    Origin = $frontendUrl
    Referer = "$frontendUrl/"
    "X-XSRF-TOKEN" = $xsrfToken
}

$securePassword = Read-Host `
    "Contraseña de admin@control-escolar.local" `
    -AsSecureString

$plainPassword = [System.Net.NetworkCredential]::new(
    "",
    $securePassword
).Password

$loginBody = @{
    email = "admin@control-escolar.local"
    password = $plainPassword
    remember = $false
} | ConvertTo-Json

try {
    Write-Host "Iniciando sesión..."

    $login = Invoke-RestMethod `
        -Uri "$backendUrl/api/auth/login" `
        -Method Post `
        -WebSession $global:webSession `
        -Headers $global:authHeaders `
        -ContentType "application/json" `
        -Body $loginBody

    Write-Host $login.message

    Write-Host "Comprobando autorización..."

    $authorization = Invoke-RestMethod `
        -Uri "$backendUrl/api/auth/authorization-check" `
        -Method Get `
        -WebSession $global:webSession `
        -Headers $global:baseHeaders

    Write-Host $authorization.message
}
catch {
    if ($_.Exception.Response) {
        $reader = New-Object System.IO.StreamReader(
            $_.Exception.Response.GetResponseStream()
        )

        $errorBody = $reader.ReadToEnd()
        $reader.Close()

        Write-Host "Respuesta del servidor:"
        Write-Host $errorBody
    }

    throw
}
finally {
    $plainPassword = $null
    $securePassword = $null
    $loginBody = $null
}