param(
    [string] $HostName = "178.156.237.134",
    [string] $User = "root",
    [string] $RemoteWpRoot = "/var/www/current-site",
    [string] $LocalThemeRoot = "pulled-vps-themes"
)

$ErrorActionPreference = "Stop"

$repoRoot = Split-Path -Parent $PSScriptRoot
$destinationRoot = Join-Path $repoRoot $LocalThemeRoot
New-Item -ItemType Directory -Force -Path $destinationRoot | Out-Null

$sshTarget = "$User@$HostName"
$theme = (& ssh $sshTarget "cd '$RemoteWpRoot' && wp --allow-root theme list --status=active --field=name" | Select-Object -First 1).Trim()

if ([string]::IsNullOrWhiteSpace($theme)) {
    throw "Could not determine active theme from ${sshTarget}:$RemoteWpRoot"
}

$remoteThemePath = "$RemoteWpRoot/wp-content/themes/$theme"
$localThemePath = Join-Path $destinationRoot $theme

Write-Host "Pulling active theme '$theme' from ${sshTarget}:$remoteThemePath"
New-Item -ItemType Directory -Force -Path $localThemePath | Out-Null

scp -r "${sshTarget}:${remoteThemePath}/." $localThemePath

Write-Host "Theme pulled to: $localThemePath"
