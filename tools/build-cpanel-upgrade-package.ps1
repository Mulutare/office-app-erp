[CmdletBinding()]
param(
    [string] $BaselineCommit =
        'f916064abc48d0d69c12db9295f4cb85e11c3408',
    [string] $BaselineArchive =
        'D:\enterprise\officeapp-cpanel.zip',
    [string] $ReleaseVersion = '2026.08.01.1',
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

if (-not (Test-Path -LiteralPath $BaselineArchive)) {
    throw 'The verified deployed-release archive is missing.'
}

$vendorAutoloader = Join-Path $projectRoot 'vendor\autoload.php'

if (-not (Test-Path -LiteralPath $vendorAutoloader)) {
    throw (
        'Locked production dependencies are missing. Run Composer with ' +
        '--no-dev --optimize-autoloader before packaging.'
    )
}

$sourceCommit = (& git -C $projectRoot rev-parse HEAD).Trim()

if ($LASTEXITCODE -ne 0 -or $sourceCommit -eq '') {
    throw 'Unable to resolve the source commit.'
}

$worktreeStatus = @(& git -C $projectRoot status --porcelain --untracked-files=all)

if ($LASTEXITCODE -ne 0) {
    throw 'Unable to capture build worktree provenance.'
}

& git -C $projectRoot cat-file -e ($BaselineCommit + '^{commit}')

if ($LASTEXITCODE -ne 0) {
    throw 'The deployed baseline commit is unavailable.'
}

$baselineHash = (
    Get-FileHash -LiteralPath $BaselineArchive -Algorithm SHA256
).Hash.ToLowerInvariant()
$expectedBaselineHash =
    '2a8a348755240bdb20322b4fe7774d6ef1801a331c3b3da98acc410087e05ea1'

if ($baselineHash -ne $expectedBaselineHash) {
    throw (
        'The deployed-release archive hash does not match the reviewed ' +
        'baseline.'
    )
}

$changedOutput = & git -C $projectRoot diff `
    --name-status $BaselineCommit --

if ($LASTEXITCODE -ne 0) {
    throw 'Unable to compare the current source with the baseline.'
}

$changes = @()

foreach ($line in $changedOutput) {
    if ([string]::IsNullOrWhiteSpace($line)) {
        continue
    }

    $parts = $line -split "`t"
    $status = $parts[0]
    $path = $parts[$parts.Count - 1].Replace('\', '/')
    $changes += [pscustomobject]@{
        Status = $status
        Path = $path
    }
}

$allowedNewMigrations = @(
    'database/migrations/023_create_attendance_web_push.sql',
    'database/migrations/024_create_attendance_sessions.sql',
    'database/migrations/025_create_attendance_scan_events.sql',
    'database/migrations/mysql/023_attendance_web_push.php',
    'database/migrations/mysql/024_attendance_sessions.php',
    'database/migrations/mysql/025_attendance_scan_events.php',
    'database/migrations/oracle/230_attendance_web_push.php',
    'database/migrations/oracle/240_attendance_sessions.php',
    'database/migrations/oracle/250_attendance_scan_events.php'
)

$privateFiles = New-Object System.Collections.Generic.List[string]
$publicFiles = New-Object System.Collections.Generic.List[string]

foreach ($change in $changes) {
    $path = $change.Path

    if ($change.Status.StartsWith('D')) {
        if (
            $path.StartsWith('app/') -or
            $path.StartsWith('bin/') -or
            $path.StartsWith('config/') -or
            $path.StartsWith('database/') -or
            $path.StartsWith('resources/') -or
            $path.StartsWith('routes/') -or
            $path.StartsWith('public/')
        ) {
            throw (
                'Runtime deletions require a separate reviewed removal ' +
                'procedure: ' + $path
            )
        }

        continue
    }

    if ($path.StartsWith('public/')) {
        $publicFiles.Add($path.Substring('public/'.Length))
        continue
    }

    if ($path.StartsWith('database/migrations/')) {
        if ($allowedNewMigrations -notcontains $path) {
            continue
        }

        $privateFiles.Add($path)
        continue
    }

    if ($path.StartsWith('database/seeds/')) {
        $privateFiles.Add($path)
        continue
    }

    if (
        $path.StartsWith('app/') -or
        $path.StartsWith('bin/') -or
        $path.StartsWith('resources/') -or
        $path.StartsWith('routes/') -or
        $path -eq 'composer.json' -or
        $path -eq 'composer.lock'
    ) {
        $privateFiles.Add($path)
        continue
    }

    if ($path.StartsWith('config/')) {
        if (
            $path -in @(
                'config/database.php',
                'config/app.local.php'
            )
        ) {
            throw 'A production secret file entered the release diff.'
        }

        $privateFiles.Add($path)
    }
}

foreach ($migration in $allowedNewMigrations) {
    if (-not $privateFiles.Contains($migration)) {
        throw ('Required new migration is missing: ' + $migration)
    }
}

if ($privateFiles.Contains(
    'database/migrations/007_add_tenant_data_isolation.sql'
)) {
    throw 'Historical migration 007 must never enter the upgrade archive.'
}

$stageRoot = Join-Path $env:TEMP (
    'officeapp-upgrade-' + [guid]::NewGuid().ToString('N')
)
$packageRoot = Join-Path $stageRoot 'package'
$privateRoot = Join-Path $packageRoot 'application-private'
$publicRoot = Join-Path $packageRoot 'public-webroot'
$archivePath = Join-Path $outputRoot 'officeapp-cpanel-upgrade.zip'
$checksumPath = $archivePath + '.sha256'
$manifestPath = Join-Path `
    $outputRoot 'officeapp-upgrade-manifest.txt'

function Copy-ReleaseFile {
    param(
        [Parameter(Mandatory = $true)]
        [string] $SourceRelativePath,
        [Parameter(Mandatory = $true)]
        [string] $DestinationRoot,
        [string] $DestinationRelativePath = ''
    )

    $source = Join-Path $projectRoot (
        $SourceRelativePath.Replace('/', '\')
    )
    $relative = if ($DestinationRelativePath -ne '') {
        $DestinationRelativePath
    } else {
        $SourceRelativePath
    }
    $destination = Join-Path $DestinationRoot (
        $relative.Replace('/', '\')
    )

    if (-not (Test-Path -LiteralPath $source -PathType Leaf)) {
        throw ('Release source file is missing: ' + $SourceRelativePath)
    }

    $parent = Split-Path -Parent $destination
    New-Item -ItemType Directory -Path $parent -Force | Out-Null
    Copy-Item -LiteralPath $source -Destination $destination
}

try {
    New-Item -ItemType Directory -Path $privateRoot -Force |
        Out-Null
    New-Item -ItemType Directory -Path $publicRoot -Force |
        Out-Null

    foreach ($path in ($privateFiles | Sort-Object -Unique)) {
        Copy-ReleaseFile `
            -SourceRelativePath $path `
            -DestinationRoot $privateRoot
    }

    foreach ($path in ($publicFiles | Sort-Object -Unique)) {
        Copy-ReleaseFile `
            -SourceRelativePath ('public/' + $path) `
            -DestinationRoot $publicRoot `
            -DestinationRelativePath $path
    }

    Copy-Item `
        -LiteralPath (Join-Path $projectRoot 'vendor') `
        -Destination $privateRoot `
        -Recurse

    $forbidden = Get-ChildItem -LiteralPath $packageRoot -Recurse -File |
        Where-Object {
            $relative = $_.FullName.Substring(
                $packageRoot.Length + 1
            ).Replace('\', '/').ToLowerInvariant()

            $relative -match '(^|/)\.git(/|$)' -or
            $relative -match '(^|/)\.env($|\.)' -or
            $relative -in @(
                'application-private/config/database.php',
                'application-private/config/app.local.php'
            ) -or
            $relative -match '(^|/)storage/(logs|cache|uploads)/' -or
            $relative -match '\.(dump|bak|backup|log)$' -or
            (
                $relative.EndsWith('.sql') -and
                -not (
                    $relative.StartsWith(
                        'application-private/database/migrations/'
                    ) -or
                    $relative.StartsWith(
                        'application-private/database/seeds/'
                    )
                )
            )
        }

    if ($forbidden) {
        throw (
            'Forbidden files entered the upgrade stage: ' +
            (($forbidden | Select-Object -ExpandProperty FullName) -join ', ')
        )
    }

    New-Item -ItemType Directory -Path $outputRoot -Force |
        Out-Null

    if (Test-Path -LiteralPath $archivePath) {
        Remove-Item -LiteralPath $archivePath -Force
    }

    # Compress-Archive preserves Windows backslashes in entry names on some
    # PowerShell/.NET combinations. Build the archive explicitly so cPanel's
    # Linux unzip always receives portable POSIX-style entry paths.
    Add-Type -AssemblyName System.IO.Compression
    $archiveStream = [System.IO.File]::Open(
        $archivePath,
        [System.IO.FileMode]::CreateNew,
        [System.IO.FileAccess]::Write,
        [System.IO.FileShare]::None
    )
    $zipArchive = [System.IO.Compression.ZipArchive]::new(
        $archiveStream,
        [System.IO.Compression.ZipArchiveMode]::Create,
        $false
    )

    try {
        foreach ($rootDefinition in @(
            [pscustomobject]@{
                Path = $privateRoot
                Entry = 'application-private/'
            },
            [pscustomobject]@{
                Path = $publicRoot
                Entry = 'public-webroot/'
            }
        )) {
            $zipArchive.CreateEntry($rootDefinition.Entry) | Out-Null

            foreach ($file in (
                Get-ChildItem -LiteralPath $rootDefinition.Path `
                    -Recurse -File |
                Sort-Object FullName
            )) {
                $relative = $file.FullName.Substring(
                    $rootDefinition.Path.Length + 1
                ).Replace('\', '/')
                $entry = $zipArchive.CreateEntry(
                    $rootDefinition.Entry + $relative,
                    [System.IO.Compression.CompressionLevel]::Optimal
                )
                $entry.LastWriteTime = $file.LastWriteTimeUtc
                $sourceStream = [System.IO.File]::OpenRead($file.FullName)
                $entryStream = $entry.Open()

                try {
                    $sourceStream.CopyTo($entryStream)
                } finally {
                    $entryStream.Dispose()
                    $sourceStream.Dispose()
                }
            }
        }
    } finally {
        $zipArchive.Dispose()
        $archiveStream.Dispose()
    }

    $archiveHash = (
        Get-FileHash -LiteralPath $archivePath -Algorithm SHA256
    ).Hash.ToLowerInvariant()
    [System.IO.File]::WriteAllText(
        $checksumPath,
        $archiveHash + '  officeapp-cpanel-upgrade.zip' +
            [Environment]::NewLine,
        [System.Text.UTF8Encoding]::new($false)
    )

    $manifest = New-Object System.Collections.Generic.List[string]
    $manifest.Add('OfficeApp ERP cPanel Upgrade Manifest')
    $manifest.Add('======================================')
    $manifest.Add('')
    $manifest.Add('Release version: ' + $ReleaseVersion)
    $manifest.Add('Source commit: ' + $sourceCommit)
    $manifest.Add('Deployed baseline commit: ' + $BaselineCommit)
    $manifest.Add('Deployed baseline archive SHA-256: ' + $baselineHash)
    $manifest.Add('Upgrade archive SHA-256: ' + $archiveHash)
    $manifest.Add('')
    $manifest.Add('BUILD WORKTREE PROVENANCE')
    $manifest.Add('-------------------------')

    if ($worktreeStatus.Count -eq 0) {
        $manifest.Add('Clean worktree')
    } else {
        foreach ($statusLine in $worktreeStatus) {
            $manifest.Add($statusLine)
        }
    }

    $manifest.Add('')
    $manifest.Add('PACKAGE POLICY')
    $manifest.Add('--------------')
    $manifest.Add('This is an overlay upgrade, not a fresh installer.')
    $manifest.Add('Historical migrations and production state are excluded.')
    $manifest.Add('The archive has exactly application-private/ and public-webroot/.')
    $manifest.Add('')
    $manifest.Add('NEW MYSQL MIGRATIONS')
    $manifest.Add('--------------------')
    $manifest.Add('023  Attendance Web Push subscriptions and delivery state')
    $manifest.Add('024  Multi-session attendance with legacy-record backfill')
    $manifest.Add('025  Immutable scan events and attendance schedule snapshots')
    $manifest.Add('')
    $manifest.Add('MIGRATION CHECKSUMS')
    $manifest.Add('-------------------')

    foreach ($file in (
        Get-ChildItem `
            -LiteralPath (Join-Path $projectRoot 'database\migrations\mysql') `
            -Filter '*.php' |
            Sort-Object Name
    )) {
        $contents = [System.IO.File]::ReadAllText($file.FullName)
        $normalized = $contents.Replace("`r`n", "`n").Replace("`r", "`n")
        $bytes = [System.Text.Encoding]::UTF8.GetBytes($normalized)
        $sha = [System.Security.Cryptography.SHA256]::Create()

        try {
            $checksum = (
                [System.BitConverter]::ToString(
                    $sha.ComputeHash($bytes)
                )
            ).Replace('-', '').ToLowerInvariant()
        } finally {
            $sha.Dispose()
        }

        $manifest.Add($file.BaseName + '  ' + $checksum)
    }

    $manifest.Add('')
    $manifest.Add('SOURCE CHANGES SINCE DEPLOYED BASELINE')
    $manifest.Add('--------------------------------------')

    foreach ($change in ($changes | Sort-Object Path)) {
        $annotation = ''

        if ($change.Path -eq 'database/migrations/007_add_tenant_data_isolation.sql') {
            $annotation = '  [EXCLUDED: historical fresh-install migration]'
        } elseif (
            -not $privateFiles.Contains($change.Path) -and
            -not (
                $change.Path.StartsWith('public/') -and
                $publicFiles.Contains(
                    $change.Path.Substring('public/'.Length)
                )
            )
        ) {
            $annotation = '  [NOT REQUIRED BY CPANEL RUNTIME]'
        }

        $manifest.Add(
            $change.Status + "`t" + $change.Path + $annotation
        )
    }

    $manifest.Add('')
    $manifest.Add('PACKAGED FILE INVENTORY (SHA-256)')
    $manifest.Add('--------------------------------')

    foreach ($file in (
        Get-ChildItem -LiteralPath $packageRoot -Recurse -File |
        Sort-Object FullName
    )) {
        $relative = $file.FullName.Substring(
            $packageRoot.Length + 1
        ).Replace('\', '/')
        $hash = (
            Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256
        ).Hash.ToLowerInvariant()
        $manifest.Add($hash + '  ' + $relative)
    }

    [System.IO.File]::WriteAllLines(
        $manifestPath,
        $manifest,
        [System.Text.UTF8Encoding]::new($false)
    )

    Write-Output ('Release:  ' + $ReleaseVersion)
    Write-Output ('Package:  ' + $archivePath)
    Write-Output ('SHA-256: ' + $archiveHash)
    Write-Output ('Manifest: ' + $manifestPath)
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
