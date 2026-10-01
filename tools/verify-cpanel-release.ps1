[CmdletBinding()]
param([Parameter(Mandatory=$true)][string]$ReleasePath)
$ErrorActionPreference='Stop';Set-StrictMode -Version Latest
. (Join-Path $PSScriptRoot 'deployment-release-common.ps1')
$release=(Resolve-Path -LiteralPath $ReleasePath).Path
$package=Join-Path $release 'officeapp-cpanel.tar.gz';$manifest=Join-Path $release 'deployment-manifest.txt';$sums=Join-Path $release 'SHA256SUMS.txt'
foreach($path in @($package,$manifest,$sums)){if(-not(Test-Path -LiteralPath $path)){throw "Missing release artifact: $path"}}
$expected=((Get-Content -LiteralPath $sums|Select-Object -First 1)-split '\s+')[0].ToLowerInvariant();$actual=(Get-FileHash -LiteralPath $package -Algorithm SHA256).Hash.ToLowerInvariant();if($expected-ne$actual){throw 'Package SHA256 does not match SHA256SUMS.txt.'}
$entries=@(tar -tzf $package|ForEach-Object{($_ -replace '\\','/').TrimStart('./')});if($LASTEXITCODE-ne 0){throw 'Package cannot be listed.'};$roots=@($entries|Where-Object{$_}|ForEach-Object{($_-split'/')[0]}|Sort-Object -Unique);if($roots.Count-ne 1-or$roots[0]-ne'office_app'){throw 'Archive root is not exactly office_app/.'}
foreach($required in @('office_app/vendor/autoload.php','office_app/storage/cache/','office_app/storage/logs/','office_app/storage/private/','office_app/storage/uploads/')){if($entries-notcontains$required){throw "Missing required archive entry: $required"}}
foreach($forbidden in @('office_app/config/database.php','office_app/config/app.local.php')){if($entries-contains$forbidden){throw "Protected configuration is present: $forbidden"}}
if($entries|Where-Object{$_-match'(^|/)(\.git|work|tests|dist|artifacts|node_modules|backups|\.deploy)(/|$)'}|Select-Object -First 1){throw 'Development content entered the archive.'}
foreach($runtime in @('cache','logs','private','uploads')){$prefix="office_app/storage/$runtime/";if($entries|Where-Object{$_.StartsWith($prefix)-and$_-ne$prefix}|Select-Object -First 1){throw "Runtime data entered $prefix"}}
if($entries|Where-Object{$_-match '(^|/)\.\.(/|$)|^[A-Za-z]:|^/'}|Select-Object -First 1){throw 'Unsafe archive path.'}
if(@($entries|Sort-Object -Unique).Count-ne$entries.Count){throw 'Duplicate archive entries.'}
$verbose=@(tar -tvzf $package);if($LASTEXITCODE-ne0-or@($verbose|Where-Object{$_-match'^l'}).Count){throw 'Symlink/unreadable archive entries.'}
$checkRoot=Join-Path ([IO.Path]::GetTempPath()) ('officeapp-verify-'+[guid]::NewGuid().ToString('N'))
try {
    New-Item -ItemType Directory -Path $checkRoot|Out-Null
    tar -xzf $package -C $checkRoot office_app/deployment/production-runner.php office_app/app/database/MigrationRunner.php
    if($LASTEXITCODE-ne0){throw 'Reviewed runner files missing from archive.'}
    $sourceRoot=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path
    foreach($relative in @('deployment/production-runner.php','app/database/MigrationRunner.php')){
        if((Get-NormalizedSourceSHA256 (Join-Path $checkRoot ('office_app/'+$relative)))-ne(Get-NormalizedSourceSHA256 (Join-Path $sourceRoot $relative))){throw "Packaged runner differs from reviewed source: $relative"}
    }
} finally {
    if((Test-Path -LiteralPath $checkRoot)-and$checkRoot.StartsWith([IO.Path]::GetTempPath(),[StringComparison]::OrdinalIgnoreCase)){Remove-Item -LiteralPath $checkRoot -Recurse -Force}
}
$manifestText=Get-Content -Raw -LiteralPath $manifest;if($manifestText-notmatch[regex]::Escape($actual)){throw 'Manifest does not contain the package hash.'}
[pscustomobject]@{Validation='PASS';Package=$package;Size=(Get-Item $package).Length;SHA256=$actual;EntryCount=$entries.Count}
