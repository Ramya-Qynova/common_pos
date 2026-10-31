<#
.SYNOPSIS
  Verifies the local dev environment matches docs/MASTER_PROMPT.md section 5.
.DESCRIPTION
  Checks PHP version + required extensions, Composer, MySQL connectivity + charset,
  Node and npm. Prints a clear PASS/FAIL per check and exits non-zero on any failure.
#>

$ErrorActionPreference = 'Continue'
$failures = 0

function Pass($msg) { Write-Host "[PASS] $msg" -ForegroundColor Green }
function Fail($msg) { Write-Host "[FAIL] $msg" -ForegroundColor Red; $script:failures++ }
function Warn($msg) { Write-Host "[WARN] $msg" -ForegroundColor Yellow }

Write-Host "== Advanced Universal POS :: environment check ==" -ForegroundColor Cyan

# --- PHP ---
$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) {
    Fail "php not found on PATH"
} else {
    $phpVersionRaw = (& php -r "echo PHP_VERSION;") 2>$null
    $minVersion = [version]"8.3.0"
    $targetVersion = [version]"8.5.0"
    try {
        $phpVersion = [version]($phpVersionRaw -replace '-.*$', '')
    } catch {
        $phpVersion = $null
    }
    if ($null -eq $phpVersion) {
        Fail "could not parse PHP version from '$phpVersionRaw'"
    } elseif ($phpVersion -ge $targetVersion) {
        Pass "PHP $phpVersionRaw (meets target 8.5.x)"
    } elseif ($phpVersion -ge $minVersion) {
        Pass "PHP $phpVersionRaw (meets minimum 8.3, below target 8.5)"
    } else {
        Warn "PHP $phpVersionRaw is below the 8.3 minimum from docs/MASTER_PROMPT.md section 5 (see docs/DECISIONS.md D-0001 for the accepted workaround: composer.json pins to ^8.2 on this machine)"
    }

    $requiredExtensions = @('pdo_mysql','mbstring','openssl','fileinfo','intl','curl','zip','bcmath','sodium','gd','Zend OPcache')
    $loaded = (& php -m) -join "`n"
    foreach ($ext in $requiredExtensions) {
        if ($loaded -match [regex]::Escape($ext)) {
            Pass "PHP extension: $ext"
        } else {
            Fail "PHP extension missing: $ext"
        }
    }
}

# --- Composer ---
$composer = Get-Command composer -ErrorAction SilentlyContinue
if (-not $composer) {
    Fail "composer not found on PATH"
} else {
    $composerVersion = (& composer --version) 2>$null
    Pass "Composer found: $composerVersion"
}

# --- MySQL ---
$mysql = Get-Command mysql -ErrorAction SilentlyContinue
if (-not $mysql) {
    Warn "mysql CLI not found on PATH; skipping connectivity/charset check (verify DB_* in backend/.env manually)"
} else {
    $dbHost = if ($env:DB_HOST) { $env:DB_HOST } else { '127.0.0.1' }
    $dbPort = if ($env:DB_PORT) { $env:DB_PORT } else { '3306' }
    $dbUser = if ($env:DB_USERNAME) { $env:DB_USERNAME } else { 'root' }
    $dbPass = if ($env:DB_PASSWORD) { $env:DB_PASSWORD } else { '' }
    try {
        $charsetResult = & mysql -h $dbHost -P $dbPort -u $dbUser --password="$dbPass" -N -e "SELECT @@character_set_server;" 2>$null
        if ($LASTEXITCODE -eq 0) {
            Pass "MySQL reachable at ${dbHost}:${dbPort} (server charset: $charsetResult)"
            if ($charsetResult -notmatch 'utf8mb4') {
                Warn "MySQL server charset is not utf8mb4 ($charsetResult) - set character-set-server=utf8mb4 for full emoji/unicode support"
            }
        } else {
            Fail "could not connect to MySQL at ${dbHost}:${dbPort} as $dbUser"
        }
    } catch {
        Fail "MySQL connectivity check errored: $($_.Exception.Message)"
    }
}

# --- Node / npm ---
$node = Get-Command node -ErrorAction SilentlyContinue
if (-not $node) {
    Fail "node not found on PATH"
} else {
    $nodeVersion = (& node -v) 2>$null
    $nodeMajor = [int]($nodeVersion -replace '^v(\d+)\..*$', '$1')
    if ($nodeMajor -ge 24) {
        Pass "Node $nodeVersion (meets target 24.x)"
    } elseif ($nodeMajor -ge 18) {
        Warn "Node $nodeVersion is below the target of 24.x but is a supported LTS; Vite/vitest will still run"
    } else {
        Fail "Node $nodeVersion is too old"
    }
}

$npm = Get-Command npm -ErrorAction SilentlyContinue
if (-not $npm) {
    Fail "npm not found on PATH"
} else {
    $npmVersion = (& npm -v) 2>$null
    Pass "npm $npmVersion"
}

Write-Host ""
if ($failures -eq 0) {
    Write-Host "== All required checks passed (see WARN lines above for non-blocking notes) ==" -ForegroundColor Green
    exit 0
} else {
    Write-Host "== $failures check(s) FAILED ==" -ForegroundColor Red
    exit 1
}
