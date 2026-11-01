<#
.SYNOPSIS
  Restores a mysqldump SQL file into a (by default, throwaway) database.
.DESCRIPTION
  Never restores over the live dev/CI database unless -TargetDatabase is
  explicitly passed as such - the default target is a clearly-named
  `_restore_test` database so a verification run can never clobber real
  data (section 15: the restore is "verified", not "applied in place").
#>
param(
    [Parameter(Mandatory = $true)][string]$BackupFile,
    [string]$TargetDatabase = $null,
    [string]$DbHost = $null,
    [int]$Port = $null,
    [string]$User = $null,
    [string]$Password = $null,
    [string]$MysqlPath = $null
)

$ErrorActionPreference = 'Stop'

if (-not $MysqlPath) {
    $candidates = @(Get-ChildItem 'C:\Program Files\MySQL\MySQL Server*\bin\mysql.exe' -ErrorAction SilentlyContinue | Sort-Object FullName -Descending | Select-Object -First 1 -ExpandProperty FullName)
    $MysqlPath = if ($candidates) { $candidates[0] } else { 'C:\xampp\mysql\bin\mysql.exe' }
}

function ReadEnvValue($key, $envPath) {
    if (-not (Test-Path $envPath)) { return $null }
    $line = Select-String -Path $envPath -Pattern "^$key=" | Select-Object -First 1
    if (-not $line) { return $null }
    return ($line.Line -split '=', 2)[1]
}

$envPath = Join-Path $PSScriptRoot '..\backend\.env'
if (-not $TargetDatabase) {
    $sourceDb = ReadEnvValue 'DB_DATABASE' $envPath
    $TargetDatabase = "$sourceDb`_restore_test"
}
if (-not $DbHost) { $DbHost = ReadEnvValue 'DB_HOST' $envPath }
if (-not $Port) { $Port = [int](ReadEnvValue 'DB_PORT' $envPath) }
if (-not $User) { $User = ReadEnvValue 'DB_USERNAME' $envPath }
if (-not $Password) { $Password = ReadEnvValue 'DB_PASSWORD' $envPath }

if (-not (Test-Path $BackupFile)) {
    Write-Host "[FAIL] Backup file not found: $BackupFile" -ForegroundColor Red
    exit 1
}
if (-not (Test-Path $MysqlPath)) {
    Write-Host "[FAIL] mysql client not found at $MysqlPath" -ForegroundColor Red
    exit 1
}

Write-Host "== Restoring $BackupFile into fresh database '$TargetDatabase' ==" -ForegroundColor Cyan

& $MysqlPath --host=$DbHost --port=$Port --user=$User --password=$Password `
    -e "DROP DATABASE IF EXISTS ``$TargetDatabase``; CREATE DATABASE ``$TargetDatabase`` CHARACTER SET utf8mb4;"
if ($LASTEXITCODE -ne 0) {
    Write-Host "[FAIL] Could not (re)create database '$TargetDatabase'" -ForegroundColor Red
    exit 1
}

Get-Content $BackupFile -Raw | & $MysqlPath --host=$DbHost --port=$Port --user=$User --password=$Password $TargetDatabase
if ($LASTEXITCODE -ne 0) {
    Write-Host "[FAIL] mysql import exited with code $LASTEXITCODE" -ForegroundColor Red
    exit 1
}

Write-Host "[PASS] Restored into '$TargetDatabase'" -ForegroundColor Green
Write-Output $TargetDatabase
