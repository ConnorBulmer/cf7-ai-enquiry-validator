$ErrorActionPreference = 'Stop'
$compose = Join-Path $PSScriptRoot 'docker-compose.yml'
$cells = @(
	@{ Php = '8.1'; Source = 'flamingo'; Port = 18081 },
	@{ Php = '8.1'; Source = 'cfdb7'; Port = 18082 },
	@{ Php = '8.3'; Source = 'flamingo'; Port = 18083 },
	@{ Php = '8.3'; Source = 'cfdb7'; Port = 18084 }
)

& npm.cmd ci "--prefix=$PSScriptRoot"
if ($LASTEXITCODE -ne 0) { throw 'Playwright dependency installation failed.' }
$playwright = Join-Path $PSScriptRoot 'node_modules/.bin/playwright.cmd'
& $playwright install chromium
if ($LASTEXITCODE -ne 0) { throw 'Playwright Chromium installation failed.' }

foreach ($cell in $cells) {
	$project = "cf7aiv-portability-$($cell.Php.Replace('.', ''))-$($cell.Source)"
	$env:CF7AIV_WORDPRESS_IMAGE = "wordpress:php$($cell.Php)-apache"
	$env:CF7AIV_CLI_IMAGE = "wordpress:cli-php$($cell.Php)"
	$env:CF7AIV_HTTP_PORT = [string] $cell.Port
	$env:CF7AIV_SOURCE_MODE = $cell.Source
	$env:CF7AIV_BASE_URL = "http://127.0.0.1:$($cell.Port)"
	try {
		& (Join-Path $PSScriptRoot 'bootstrap.ps1') -Project $project -SourceMode $cell.Source -Port $cell.Port
		if ($LASTEXITCODE -ne 0) { throw 'Bootstrap failed.' }
		& docker compose -p $project -f $compose run --rm -e "CF7AIV_SOURCE_MODE=$($cell.Source)" cli eval-file /var/www/html/wp-content/plugins/cf7-ai-enquiry-validator/tests/wordpress/runtime-assertions.php before
		if ($LASTEXITCODE -ne 0) { throw 'Pre-browser runtime assertions failed.' }
		Push-Location $PSScriptRoot
		try {
			& $playwright test portability.spec.js --workers=1 --reporter=line --output=test-results
		} finally {
			Pop-Location
		}
		if ($LASTEXITCODE -ne 0) { throw 'Browser assertions failed.' }
		& docker compose -p $project -f $compose run --rm -e "CF7AIV_SOURCE_MODE=$($cell.Source)" cli eval-file /var/www/html/wp-content/plugins/cf7-ai-enquiry-validator/tests/wordpress/runtime-assertions.php after
		if ($LASTEXITCODE -ne 0) { throw 'Post-browser runtime assertions failed.' }
		Write-Output "PASS matrix php=$($cell.Php) source=$($cell.Source)"
	} finally {
		& docker compose -p $project -f $compose down --volumes --remove-orphans
	}
}
