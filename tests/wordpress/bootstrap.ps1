param(
	[Parameter(Mandatory = $true)][string]$Project,
	[Parameter(Mandatory = $true)][string]$SourceMode,
	[Parameter(Mandatory = $true)][int]$Port
)

$ErrorActionPreference = 'Stop'
$compose = Join-Path $PSScriptRoot 'docker-compose.yml'
function Invoke-Compose([string[]]$Arguments) {
	& docker compose -p $Project -f $compose @Arguments
	if ($LASTEXITCODE -ne 0) { throw "Docker Compose failed: $($Arguments -join ' ')" }
}

Invoke-Compose @('up', '-d', 'db', 'wordpress')
for ($attempt = 0; $attempt -lt 60; $attempt++) {
	$ready = & docker compose -p $Project -f $compose exec -T wordpress sh -c 'test -f /var/www/html/wp-load.php && test -f /var/www/html/wp-config.php && grep -q wp-settings.php /var/www/html/wp-config.php && echo ready || true' 2>$null
	if ($ready -contains 'ready') { break }
	Start-Sleep -Seconds 2
}
if ($attempt -ge 60) { throw 'WordPress files and configuration did not become ready.' }

$url = "http://127.0.0.1:$Port"
Invoke-Compose @('run', '--rm', 'cli', 'core', 'install', "--url=$url", '--title=CF7AIV Portability', '--admin_user=admin', '--admin_password=portability-test-only', '--admin_email=admin@example.test', '--skip-email')
Invoke-Compose @('run', '--rm', 'cli', 'plugin', 'install', 'contact-form-7', '--activate')
Invoke-Compose @('run', '--rm', 'cli', 'plugin', 'activate', 'cf7-ai-enquiry-validator')
if ($SourceMode -eq 'flamingo') {
	Invoke-Compose @('run', '--rm', 'cli', 'plugin', 'install', 'flamingo', '--activate')
} elseif ($SourceMode -eq 'cfdb7') {
	Invoke-Compose @('run', '--rm', 'cli', 'plugin', 'install', 'contact-form-cfdb7', '--activate')
} else { throw "Unsupported source mode: $SourceMode" }
Invoke-Compose @('run', '--rm', 'cli', 'cf7aiv', 'migrate')
Invoke-Compose @('run', '--rm', 'cli', 'eval-file', '/var/www/html/wp-content/plugins/cf7-ai-enquiry-validator/tests/wordpress/bootstrap.php')
Invoke-Compose @('run', '--rm', 'cli', 'rewrite', 'structure', '/%postname%/', '--hard')
