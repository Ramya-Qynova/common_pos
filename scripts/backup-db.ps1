<#
.SYNOPSIS
  Dumps the Advanced Universal POS database to a timestamped SQL file.
.DESCRIPTION
  section 15 Final Acceptance: "a backup has been restored and verified."
  Plain mysqldump - no proprietary format, so the dump is restorable with
  nothing but a stock MySQL client (section 5's own "no vendor lock-in"
  spirit). Credentials come from backend/.env by default, never hardcoded.
#>
param(
    [string]$Database = $null,
    [string]$DbHost = $null,
    [int]$Port = $null,
    [string]$User = $null,
    [string]$Password = $null,
    [string]$OutDir = (Join-Path $PSScriptRoot '..\backups'),
    [string]$MysqldumpPath = $null
)

$ErrorActionPreference = 'Stop'

if (-not $MysqldumpPath) {
    # The bundled XAMPP client is MariaDB-flavoured and lacks the
    # caching_sha2_password plugin a real MySQL 8/9 server needs - prefer a
    # client that actually ships from a MySQL Server install if one exists
    # on this machine, falling back to XAMPP's otherwise.
    $candidates = @(Get-ChildItem 'C:\Program Files\MySQL\MySQL Server*\bin\mysqldump.exe' -ErrorAction SilentlyContinue | Sort-Object FullName -Descending | Select-Object -First 1 -ExpandProperty FullName)
    $MysqldumpPath = if ($candidates) { $candidates[0] } else { 'C:\xampp\mysql\bin\mysqldump.exe' }
}

function ReadEnvValue($key, $envPath) {
    if (-not (Test-Path $envPath)) { return $null }
    $line = Select-String -Path $envPath -Pattern "^$key=" | Select-Object -First 1
    if (-not $line) { return $null }
    return ($line.Line -split '=', 2)[1]
}

$envPath = Join-Path $PSScriptRoot '..\backend\.env'
if (-not $Database) { $Database = ReadEnvValue 'DB_DATABASE' $envPath }
if (-not $DbHost) { $DbHost = ReadEnvValue 'DB_HOST' $envPath }
if (-not $Port) { $Port = [int](ReadEnvValue 'DB_PORT' $envPath) }
if (-not $User) { $User = ReadEnvValue 'DB_USERNAME' $envPath }
if (-not $Password) { $Password = ReadEnvValue 'DB_PASSWORD' $envPath }

if (-not (Test-Path $MysqldumpPath)) {
    Write-Host "[FAIL] mysqldump not found at $MysqldumpPath" -ForegroundColor Red
    exit 1
}

if (-not (Test-Path $OutDir)) {
    New-Item -ItemType Directory -Path $OutDir -Force | Out-Null
}

$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$outFile = Join-Path $OutDir "$Database-$timestamp.sql"

Write-Host "== Backing up '$Database' to $outFile ==" -ForegroundColor Cyan

& $MysqldumpPath --host=$DbHost --port=$Port --user=$User --password=$Password `
    --single-transaction --routines --triggers --add-drop-table `
    $Database | Out-File -FilePath $outFile -Encoding utf8

if ($LASTEXITCODE -ne 0) {
    Write-Host "[FAIL] mysqldump exited with code $LASTEXITCODE" -ForegroundColor Red
    exit 1
}

$size = (Get-Item $outFile).Length
if ($size -lt 1024) {
    Write-Host "[FAIL] backup file is suspiciously small ($size bytes) - likely empty/failed dump" -ForegroundColor Red
    exit 1
}

Write-Host "[PASS] Backup written: $outFile ($size bytes)" -ForegroundColor Green
Write-Output $outFile
