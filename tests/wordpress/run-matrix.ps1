$ErrorActionPreference = 'Stop'
$compose = Join-Path $PSScriptRoot 'docker-compose.yml'
$cells = @(
	@{ WordPress = '6.9'; Php = '8.1'; Image = 'wordpress:6.9-php8.1-apache'; Source = 'flamingo'; Port = 18081 },
	@{ WordPress = '6.9'; Php = '8.1'; Image = 'wordpress:6.9-php8.1-apache'; Source = 'cfdb7'; Port = 18082 },
	@{ WordPress = '7.0.2'; Php = '8.3'; Image = 'wordpress:7.0.2-php8.3-apache'; Source = 'flamingo'; Port = 18083 },
	@{ WordPress = '7.0.2'; Php = '8.3'; Image = 'wordpress:7.0.2-php8.3-apache'; Source = 'cfdb7'; Port = 18084 }
)

& npm.cmd ci "--prefix=$PSScriptRoot"
if ($LASTEXITCODE -ne 0) { throw 'Playwright dependency installation failed.' }
$playwright = Join-Path $PSScriptRoot 'node_modules/.bin/playwright.cmd'
& $playwright install chromium
if ($LASTEXITCODE -ne 0) { throw 'Playwright Chromium installation failed.' }

foreach ($cell in $cells) {
	$project = "cf7aiv-portability-$($cell.Php.Replace('.', ''))-$($cell.Source)"
	$env:CF7AIV_WORDPRESS_IMAGE = $cell.Image
	$env:CF7AIV_CLI_IMAGE = "wordpress:cli-php$($cell.Php)"
	$env:CF7AIV_WORDPRESS_VERSION = $cell.WordPress
	$env:CF7AIV_HTTP_PORT = [string] $cell.Port
	$env:CF7AIV_SOURCE_MODE = $cell.Source
	$env:CF7AIV_BASE_URL = "http://127.0.0.1:$($cell.Port)"
	try {
		& (Join-Path $PSScriptRoot 'bootstrap.ps1') -Project $project -SourceMode $cell.Source -Port $cell.Port
		if ($LASTEXITCODE -ne 0) { throw 'Bootstrap failed.' }
		& docker compose -p $project -f $compose run --rm -e "CF7AIV_SOURCE_MODE=$($cell.Source)" -e "CF7AIV_WORDPRESS_VERSION=$($cell.WordPress)" cli eval-file /var/www/html/wp-content/plugins/cf7-ai-enquiry-validator/tests/wordpress/runtime-assertions.php before
		if ($LASTEXITCODE -ne 0) { throw 'Pre-browser runtime assertions failed.' }
		Push-Location $PSScriptRoot
		try {
			& $playwright test portability.spec.js --workers=1 --reporter=line --output=test-results
		} finally {
			Pop-Location
		}
		if ($LASTEXITCODE -ne 0) { throw 'Browser assertions failed.' }
		& docker compose -p $project -f $compose run --rm -e "CF7AIV_SOURCE_MODE=$($cell.Source)" -e "CF7AIV_WORDPRESS_VERSION=$($cell.WordPress)" cli eval-file /var/www/html/wp-content/plugins/cf7-ai-enquiry-validator/tests/wordpress/runtime-assertions.php after
		if ($LASTEXITCODE -ne 0) { throw 'Post-browser runtime assertions failed.' }
		Write-Output "PASS matrix php=$($cell.Php) source=$($cell.Source)"
	} finally {
		& docker compose -p $project -f $compose down --volumes --remove-orphans
	}
}
