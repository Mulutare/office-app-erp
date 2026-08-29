[CmdletBinding()]
param([string] $OutputDirectory = 'dist')

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$outputRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot $OutputDirectory))
$projectPrefix = $projectRoot.TrimEnd([IO.Path]::DirectorySeparatorChar, [IO.Path]::AltDirectorySeparatorChar) + [IO.Path]::DirectorySeparatorChar
if (-not $outputRoot.StartsWith($projectPrefix, [StringComparison]::OrdinalIgnoreCase)) { throw 'The output directory must be inside the project.' }
foreach ($tool in @('git', 'docker', 'tar')) {
    if (-not (Get-Command $tool -ErrorAction SilentlyContinue)) { throw "Required build tool is unavailable: $tool" }
}
docker info --format '{{.ServerVersion}}' 2>$null | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Docker is installed but its Linux engine is unavailable. Start Docker Desktop and rerun the command.' }

$commitSha = (git -C $projectRoot rev-parse HEAD).Trim()
if ($LASTEXITCODE -ne 0 -or $commitSha -notmatch '^[0-9a-f]{40}$') { throw 'Unable to determine the current Git commit SHA.' }
$composerLock = Join-Path $projectRoot 'composer.lock'
if (-not (Test-Path -LiteralPath $composerLock)) { throw 'composer.lock is required.' }
$lockHash = (Get-FileHash -LiteralPath $composerLock -Algorithm SHA256).Hash.ToLowerInvariant()
$timestamp = [DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')
$stageRoot = Join-Path ([IO.Path]::GetTempPath()) ('officeapp-cpanel-' + [guid]::NewGuid().ToString('N'))
$packageRoot = Join-Path $stageRoot 'office_app'
$vendorExport = Join-Path $stageRoot 'vendor-export'
$imageTag = 'officeapp-cpanel-deps:' + $commitSha.Substring(0, 12)
$containerName = 'officeapp-cpanel-deps-' + [guid]::NewGuid().ToString('N')
$archivePath = Join-Path $outputRoot 'officeapp-cpanel.tar.gz'
$manifestPath = Join-Path $outputRoot 'deployment-manifest.txt'
$containerCreated = $false
$directories = @('app', 'bin', 'config', 'database', 'deployment', 'docs', 'public', 'resources', 'routes')
$rootFiles = @('.htaccess', 'composer.json', 'composer.lock', 'index.php', 'readme.md')
$runtimeDirectories = @('storage/cache', 'storage/logs', 'storage/private', 'storage/uploads')
$requiredEntries = @('office_app/app/', 'office_app/bin/', 'office_app/config/', 'office_app/database/', 'office_app/deployment/', 'office_app/docs/', 'office_app/public/', 'office_app/resources/', 'office_app/routes/', 'office_app/storage/', 'office_app/vendor/', 'office_app/vendor/autoload.php', 'office_app/storage/cache/', 'office_app/storage/logs/', 'office_app/storage/private/', 'office_app/storage/uploads/')

try {
    New-Item -ItemType Directory -Path $packageRoot -Force | Out-Null
    docker build --target php-dependencies --tag $imageTag --file (Join-Path $projectRoot 'Dockerfile') $projectRoot
    if ($LASTEXITCODE -ne 0) { throw 'Production Composer dependency build failed.' }
    docker create --name $containerName $imageTag | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Unable to create the dependency export container.' }
    $containerCreated = $true
    New-Item -ItemType Directory -Path $vendorExport -Force | Out-Null
    docker cp ($containerName + ':/app/vendor/.') $vendorExport
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath (Join-Path $vendorExport 'autoload.php'))) { throw 'The production vendor export is incomplete.' }
    foreach ($directory in $directories) {
        $source = Join-Path $projectRoot $directory
        if (-not (Test-Path -LiteralPath $source)) { throw "Required source directory is missing: $directory" }
        Copy-Item -LiteralPath $source -Destination $packageRoot -Recurse
    }
    foreach ($file in $rootFiles) {
        $source = Join-Path $projectRoot $file
        if (Test-Path -LiteralPath $source) { Copy-Item -LiteralPath $source -Destination $packageRoot }
    }
    Copy-Item -LiteralPath $vendorExport -Destination (Join-Path $packageRoot 'vendor') -Recurse
    foreach ($relative in $runtimeDirectories) { New-Item -ItemType Directory -Path (Join-Path $packageRoot $relative) -Force | Out-Null }
    foreach ($protected in @('config/database.php', 'config/app.local.php')) {
        $path = Join-Path $packageRoot $protected
        if (Test-Path -LiteralPath $path) { Remove-Item -LiteralPath $path -Force }
    }
    $storageRoot = Join-Path $packageRoot 'storage'
    Get-ChildItem -LiteralPath $storageRoot -Force | ForEach-Object {
        if ($_.Name.ToLowerInvariant() -notin @('cache', 'logs', 'private', 'uploads')) { Remove-Item -LiteralPath $_.FullName -Recurse -Force }
    }
    foreach ($relative in $runtimeDirectories) { Get-ChildItem -LiteralPath (Join-Path $packageRoot $relative) -Force | Remove-Item -Recurse -Force }
    New-Item -ItemType Directory -Path $outputRoot -Force | Out-Null
    if (Test-Path -LiteralPath $archivePath) { Remove-Item -LiteralPath $archivePath -Force }
    if (Test-Path -LiteralPath $manifestPath) { Remove-Item -LiteralPath $manifestPath -Force }
    tar -C $stageRoot -czf $archivePath office_app
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $archivePath)) { throw 'TAR.GZ creation failed.' }
    $entries = @(tar -tzf $archivePath | ForEach-Object { ($_ -replace '\\', '/').TrimStart('./') })
    if ($LASTEXITCODE -ne 0) { throw 'The generated TAR.GZ cannot be opened.' }
    $topLevels = @($entries | Where-Object { $_ } | ForEach-Object { ($_ -split '/')[0] } | Sort-Object -Unique)
    if ($topLevels.Count -ne 1 -or $topLevels[0] -ne 'office_app') { throw 'Archive top-level directory must be exactly office_app/.' }
    foreach ($required in $requiredEntries) { if (-not ($entries -contains $required)) { throw "Required archive entry is missing: $required" } }
    foreach ($forbidden in @('office_app/config/database.php', 'office_app/config/app.local.php')) { if ($entries -contains $forbidden) { throw "Protected configuration entered the archive: $forbidden" } }
    if ($entries | Where-Object { $_ -match '(^|/)(\.git|tests|dist|artifacts|node_modules)(/|$)' }) { throw 'Development-only content entered the archive.' }
    if ($entries | Where-Object { $_ -match '^[A-Za-z]:|^/' }) { throw 'An absolute filesystem path entered the archive.' }
    foreach ($relative in @('storage/cache/', 'storage/logs/', 'storage/private/', 'storage/uploads/')) {
        $prefix = 'office_app/' + $relative
        if ($entries | Where-Object { $_.StartsWith($prefix) -and $_ -ne $prefix }) { throw "Runtime data entered the archive below $prefix" }
    }
    $package = Get-Item -LiteralPath $archivePath
    $hash = (Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash.ToLowerInvariant()
    $migrations = @(Get-ChildItem -LiteralPath (Join-Path $projectRoot 'database/migrations/mysql') -Filter '*.php' -File | Sort-Object Name | ForEach-Object Name)
    $manifest = @('OfficeApp ERP cPanel Deployment Manifest', "Git commit SHA: $commitSha", "Build timestamp (UTC): $timestamp", "Package filename: $($package.Name)", "Package size (bytes): $($package.Length)", "SHA256: $hash", 'Target PHP version: 8.1 / ea-php81', "Composer lock SHA256: $lockHash", 'Migrations contained in the release:', ($migrations | ForEach-Object { "- $_" }))
    Set-Content -LiteralPath $manifestPath -Value $manifest -Encoding UTF8
    Write-Output ('Package:  ' + $archivePath)
    Write-Output ('Size:     ' + $package.Length + ' bytes')
    Write-Output ('SHA256:   ' + $hash)
    Write-Output ('Manifest: ' + $manifestPath)
    Write-Output 'Validation: PASS'
} finally {
    if ($containerCreated) { docker rm -f $containerName 2>$null | Out-Null }
    if ((Test-Path -LiteralPath $stageRoot) -and $stageRoot.StartsWith([IO.Path]::GetTempPath(), [StringComparison]::OrdinalIgnoreCase)) { Remove-Item -LiteralPath $stageRoot -Recurse -Force }
}
