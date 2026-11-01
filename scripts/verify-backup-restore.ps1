<#
.SYNOPSIS
  section 15 Final Acceptance / section 12 Phase 8 exit gate: "a backup
  has been restored and verified" / "Restore test succeeds and integrity
  checks pass."
.DESCRIPTION
  Backs up the real database, restores it into a disposable
  `<db>_restore_test` database, compares every table's row count between
  source and restore, re-derives the one G5 invariant this build cares
  about most (stock balance == ledger sum) against the restored copy, and
  drops the disposable database afterward. Exits non-zero on ANY mismatch.
#>
param(
    [string]$MysqlPath = $null,
    [string]$MysqldumpPath = $null
)

$ErrorActionPreference = 'Stop'

if (-not $MysqlPath) {
    $candidates = @(Get-ChildItem 'C:\Program Files\MySQL\MySQL Server*\bin\mysql.exe' -ErrorAction SilentlyContinue | Sort-Object FullName -Descending | Select-Object -First 1 -ExpandProperty FullName)
    $MysqlPath = if ($candidates) { $candidates[0] } else { 'C:\xampp\mysql\bin\mysql.exe' }
}
if (-not $MysqldumpPath) {
    $candidates = @(Get-ChildItem 'C:\Program Files\MySQL\MySQL Server*\bin\mysqldump.exe' -ErrorAction SilentlyContinue | Sort-Object FullName -Descending | Select-Object -First 1 -ExpandProperty FullName)
    $MysqldumpPath = if ($candidates) { $candidates[0] } else { 'C:\xampp\mysql\bin\mysqldump.exe' }
}
$failures = 0
function Pass($msg) { Write-Host "[PASS] $msg" -ForegroundColor Green }
function Fail($msg) { Write-Host "[FAIL] $msg" -ForegroundColor Red; $script:failures++ }

function ReadEnvValue($key, $envPath) {
    $line = Select-String -Path $envPath -Pattern "^$key=" | Select-Object -First 1
    return ($line.Line -split '=', 2)[1]
}

$envPath = Join-Path $PSScriptRoot '..\backend\.env'
$dbHost = ReadEnvValue 'DB_HOST' $envPath
$port = [int](ReadEnvValue 'DB_PORT' $envPath)
$user = ReadEnvValue 'DB_USERNAME' $envPath
$password = ReadEnvValue 'DB_PASSWORD' $envPath
$sourceDb = ReadEnvValue 'DB_DATABASE' $envPath
$restoreDb = "$sourceDb`_restore_test"

Write-Host "== Backup/restore verification: $sourceDb -> $restoreDb ==" -ForegroundColor Cyan

# 1. Backup
$backupFile = & (Join-Path $PSScriptRoot 'backup-db.ps1') | Select-Object -Last 1
if (-not (Test-Path $backupFile)) {
    Fail "Backup step did not produce a file"
    exit 1
}
Pass "Backup created: $backupFile"

# 2. Restore into a disposable database
& (Join-Path $PSScriptRoot 'restore-db.ps1') -BackupFile $backupFile | Out-Null
if ($LASTEXITCODE -ne 0) {
    Fail "Restore step failed"
    exit 1
}
Pass "Restore completed into $restoreDb"

# 3. Integrity: every table's row count must match between source and restore.
$tablesQuery = "SELECT table_name FROM information_schema.tables WHERE table_schema = '$sourceDb' AND table_type = 'BASE TABLE';"
$tables = & $MysqlPath --host=$dbHost --port=$port --user=$user --password=$password -N -e $tablesQuery

$mismatches = 0
foreach ($table in $tables) {
    $table = $table.Trim()
    if (-not $table) { continue }

    $sourceCount = (& $MysqlPath --host=$dbHost --port=$port --user=$user --password=$password -N -e "SELECT COUNT(*) FROM ``$sourceDb``.``$table``;").Trim()
    $restoreCount = (& $MysqlPath --host=$dbHost --port=$port --user=$user --password=$password -N -e "SELECT COUNT(*) FROM ``$restoreDb``.``$table``;").Trim()

    if ($sourceCount -ne $restoreCount) {
        Fail "Row count mismatch on '$table': source=$sourceCount restore=$restoreCount"
        $mismatches++
    }
}
if ($mismatches -eq 0) {
    Pass "Row counts match on all $($tables.Count) tables"
}

# 4. A real business invariant (G5), re-derived from the RESTORED copy: every
# stock_balances row's quantity equals the signed sum of its own stock_ledger rows.
$invariantQuery = @"
SELECT COUNT(*) FROM ``$restoreDb``.stock_balances b
WHERE b.quantity <> (
    SELECT COALESCE(SUM(CASE WHEN l.direction = 'in' THEN l.quantity ELSE -l.quantity END), 0)
    FROM ``$restoreDb``.stock_ledger l
    WHERE l.warehouse_id = b.warehouse_id AND l.product_id = b.product_id
      AND l.variant_id <=> b.variant_id AND l.batch_id <=> b.batch_id
);
"@
$invariantViolations = (& $MysqlPath --host=$dbHost --port=$port --user=$user --password=$password -N -e $invariantQuery).Trim()
if ($invariantViolations -eq '0') {
    Pass "Restored data integrity check: every stock_balances row still equals its stock_ledger sum (G5)"
} else {
    Fail "Restored data integrity check: $invariantViolations stock_balances row(s) do not equal their ledger sum"
}

# 5. Clean up the disposable database - never touch the real one.
& $MysqlPath --host=$dbHost --port=$port --user=$user --password=$password -e "DROP DATABASE IF EXISTS ``$restoreDb``;"
Pass "Disposable database '$restoreDb' dropped"

if ($failures -gt 0) {
    Write-Host "`n$failures check(s) FAILED" -ForegroundColor Red
    exit 1
}
Write-Host "`nAll backup/restore integrity checks PASSED" -ForegroundColor Green
exit 0
