# deploy.ps1 - build the client and sync dist/ to 50.12 (diff/merge upload).
#
# Run from THIS folder (client/).
#
# Usage:
#   .\deploy.ps1                 # build, then sync changed files
#   .\deploy.ps1 -DryRun         # show what WOULD change, transfer nothing
#   .\deploy.ps1 -SkipBuild      # sync existing dist/ without rebuilding
#
param(
    [switch]$SkipBuild,
    [switch]$DryRun
)

$ErrorActionPreference = 'Stop'

# --- settings ------------------------------------------------------------
$WinScp     = 'C:\Program Files (x86)\WinSCP\WinSCP.com'
$LocalDist  = Join-Path $PSScriptRoot 'dist'
$RemotePath = '/var/www/html/playground/doromal/projects-assistant-tool/'
$SftpHost   = '192.168.50.12'
$SftpUser   = 'root'
$SftpPass   = 'softdevFTW'
# Same physical server as lh_transfer_contact_v2, so the same pinned host key.
$HostKey    = 'ssh-rsa 2048 P0nkaKuPDHKOfSMlCCkUBEa1FvrjwZ1qHpHLd/kVXlE'
# -------------------------------------------------------------------------

if (-not (Test-Path $WinScp)) { throw "WinSCP not found at $WinScp - install WinSCP or edit the path." }

if (-not $SkipBuild) {
    Write-Host '==> Building (npm run build)...' -ForegroundColor Cyan
    npm run build --prefix $PSScriptRoot
    if ($LASTEXITCODE -ne 0) { throw 'Build failed; aborting deploy.' }
}

if (-not (Test-Path $LocalDist)) { throw "dist/ not found at $LocalDist - run a build first." }

# -delete removes remote files no longer in dist/ (clears stale hashed assets).
# -criteria=size,time: upload when size OR mod-time differs (index.html keeps the
# same byte size when only the hashed bundle name inside it changes).
# -filemask="|uploads/" EXCLUDES the server's uploads/ dir (user-uploaded images)
# so -delete never wipes it on deploy.
$syncOpts = '-delete -criteria=size,time -filemask="|uploads/"'
if ($DryRun) { $syncOpts = "$syncOpts -preview" }

$script = @"
option batch abort
option confirm off
open sftp://${SftpUser}:${SftpPass}@${SftpHost}:22/ -hostkey="$HostKey"
synchronize remote $syncOpts "$LocalDist" "$RemotePath"
close
exit
"@

$tmp = [IO.Path]::GetTempFileName()
Set-Content -Path $tmp -Value $script -Encoding ASCII

Write-Host "==> Syncing dist/ -> ${SftpHost}:${RemotePath}" -ForegroundColor Cyan
& $WinScp /ini=nul "/script=$tmp"
$code = $LASTEXITCODE
Remove-Item $tmp -Force

if ($code -eq 0) { Write-Host '==> Deploy complete.' -ForegroundColor Green }
else { throw "WinSCP exited with code $code" }
