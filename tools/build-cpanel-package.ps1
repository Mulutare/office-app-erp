[CmdletBinding()]
param(
    [string] $OutputDirectory = 'dist'
)

$ErrorActionPreference = 'Stop'
$projectRoot = (
    Resolve-Path (Join-Path $PSScriptRoot '..')
).Path
$outputRoot = [System.IO.Path]::GetFullPath(
    (Join-Path $projectRoot $OutputDirectory)
)
$projectPrefix = $projectRoot.TrimEnd(
    [System.IO.Path]::DirectorySeparatorChar,
    [System.IO.Path]::AltDirectorySeparatorChar
) + [System.IO.Path]::DirectorySeparatorChar

if (-not $outputRoot.StartsWith(
    $projectPrefix,
    [System.StringComparison]::OrdinalIgnoreCase
)) {
    throw 'The output directory must be inside the project.'
}

$stageRoot = Join-Path $env:TEMP (
    'officeapp-cpanel-' + [guid]::NewGuid().ToString('N')
)
$packageRoot = Join-Path $stageRoot 'office_app'
$archivePath = Join-Path $outputRoot 'officeapp-cpanel.zip'
$directories = @(
    'app',
    'bin',
    'config',
    'database',
    'deployment',
    'docs',
    'public',
    'resources',
    'routes',
    'storage',
    'vendor'
)
$rootFiles = @(
    '.htaccess',
    'README.md',
    'composer.json',
    'composer.lock'
)

$composerAutoloader = Join-Path `
    $projectRoot 'vendor\autoload.php'

if (-not (Test-Path -LiteralPath $composerAutoloader)) {
    throw (
        'Composer dependencies are missing. Run ' +
        '"composer install --no-dev --optimize-autoloader" ' +
        'before building the cPanel package.'
    )
}

try {
    New-Item -ItemType Directory -Path $packageRoot |
        Out-Null

    foreach ($directory in $directories) {
        Copy-Item `
            -LiteralPath (Join-Path $projectRoot $directory) `
            -Destination $packageRoot `
            -Recurse
    }

    foreach ($file in $rootFiles) {
        Copy-Item `
            -LiteralPath (Join-Path $projectRoot $file) `
            -Destination $packageRoot
    }

    @(
        (Join-Path $packageRoot 'config\database.php'),
        (Join-Path $packageRoot 'config\app.local.php')
    ) | ForEach-Object {
        if (Test-Path -LiteralPath $_) {
            Remove-Item -LiteralPath $_ -Force
        }
    }

    Get-ChildItem `
        -LiteralPath (Join-Path $packageRoot 'storage') `
        -File `
        -Recurse |
        Where-Object { $_.Name -ne '.gitkeep' } |
        Remove-Item -Force

    New-Item -ItemType Directory -Path $outputRoot -Force |
        Out-Null

    if (Test-Path -LiteralPath $archivePath) {
        Remove-Item -LiteralPath $archivePath -Force
    }

    Compress-Archive `
        -LiteralPath $packageRoot `
        -DestinationPath $archivePath `
        -CompressionLevel Optimal

    $hash = Get-FileHash `
        -LiteralPath $archivePath `
        -Algorithm SHA256

    Write-Output ('Package: ' + $archivePath)
    Write-Output ('SHA256:  ' + $hash.Hash)
} finally {
    if (
        (Test-Path -LiteralPath $stageRoot) -and
        $stageRoot.StartsWith(
            $env:TEMP,
            [System.StringComparison]::OrdinalIgnoreCase
        )
    ) {
        Remove-Item -LiteralPath $stageRoot -Recurse -Force
    }
}
