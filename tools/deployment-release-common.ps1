Set-StrictMode -Version Latest
function Get-NormalizedSourceSHA256([string]$Path) {
    $text=[IO.File]::ReadAllText($Path).Replace("`r`n","`n").Replace("`r","`n")
    $sha=[Security.Cryptography.SHA256]::Create()
    try { return ([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($text)))).Replace('-','').ToLowerInvariant() } finally { $sha.Dispose() }
}
function Format-RunnerHttpFailure([string]$Action,$ErrorRecord) {
    $status='unavailable';$body='';$response=$null
    if($ErrorRecord.Exception.PSObject.Properties.Name-contains'Response'){$response=$ErrorRecord.Exception.Response}
    if($null-ne$response){
        $status=[int]$response.StatusCode
        try{if($response.PSObject.Methods.Name-contains'GetResponseStream'){
            $reader=New-Object IO.StreamReader($response.GetResponseStream())
            try{$body=$reader.ReadToEnd()}finally{$reader.Dispose()}
        } elseif($response.PSObject.Properties.Name-contains'Content') {
            $body=$response.Content.ReadAsStringAsync().GetAwaiter().GetResult()
        }}catch{}
    }
    if([string]::IsNullOrWhiteSpace($body)-and$null-ne$ErrorRecord.ErrorDetails){$body=$ErrorRecord.ErrorDetails.Message}
    $code='non_json_error';$message='Remote error body was empty or invalid JSON.'
    try{$json=$body|ConvertFrom-Json -ErrorAction Stop;if($json.PSObject.Properties.Name-contains'error'){$code=[string]$json.error};if($json.PSObject.Properties.Name-contains'message'){$message=[string]$json.message}}catch{}
    return "Runner action=$Action HTTP=$status error=$code message=$message"
}
function Write-SealedRelease([string]$Root,$Release,[string]$Commit) {
    $package=Join-Path $Release.ReleasePath 'officeapp-cpanel.tar.gz'
    $manifest=Join-Path $Release.ReleasePath 'deployment-manifest.txt'
    $approval=[ordered]@{State='SEALED/APPROVED';Commit=$Commit;PackagePath=[IO.Path]::GetFullPath($package);PackageSHA256=(Get-FileHash -LiteralPath $package -Algorithm SHA256).Hash.ToLowerInvariant();PackageSize=(Get-Item -LiteralPath $package).Length;ManifestSHA256=(Get-FileHash -LiteralPath $manifest -Algorithm SHA256).Hash.ToLowerInvariant();ChecksumFileSHA256=(Get-FileHash -LiteralPath (Join-Path $Release.ReleasePath 'SHA256SUMS.txt') -Algorithm SHA256).Hash.ToLowerInvariant();RunnerSHA256=Get-NormalizedSourceSHA256 (Join-Path $Root 'deployment/production-runner.php');MigrationRunnerSHA256=Get-NormalizedSourceSHA256 (Join-Path $Root 'app/database/MigrationRunner.php');LatestMigration=[int]$Release.LatestMigration;VerificationUTC=[DateTime]::UtcNow.ToString('o')}
    $path=Join-Path $Root '.deploy/approved-release.json'
    New-Item -ItemType Directory -Force (Split-Path $path) | Out-Null
    $approval|ConvertTo-Json|Set-Content -LiteralPath $path -Encoding utf8
    return [pscustomobject]$approval
}
function Read-SealedRelease([string]$Root,[string]$Commit,[string]$OriginCommit,[bool]$TrackedDirty) {
    $path=Join-Path $Root '.deploy/approved-release.json'
    if(-not(Test-Path -LiteralPath $path)){throw 'Execute requires a SEALED/APPROVED VerifyOnly artifact.'}
    $a=Get-Content -Raw -LiteralPath $path|ConvertFrom-Json
    if($a.State-ne'SEALED/APPROVED'-or$a.Commit-ne$Commit-or$Commit-ne$OriginCommit-or$TrackedDirty){throw 'Approved commit/HEAD/origin or clean tracked tree invariant failed.'}
    $expected=Join-Path $Root ('dist/releases/'+$Commit.Substring(0,7)+'/officeapp-cpanel.tar.gz')
    if([IO.Path]::GetFullPath($a.PackagePath)-ne[IO.Path]::GetFullPath($expected)){throw 'Approved package path is outside the exact release.'}
    $release=Split-Path $expected;$manifest=Join-Path $release 'deployment-manifest.txt'
    if((Get-Item -LiteralPath $expected).Length-ne[int64]$a.PackageSize-or(Get-FileHash -LiteralPath $expected -Algorithm SHA256).Hash.ToLowerInvariant()-ne$a.PackageSHA256){throw 'Sealed package bytes changed.'}
    if((Get-FileHash -LiteralPath $manifest -Algorithm SHA256).Hash.ToLowerInvariant()-ne$a.ManifestSHA256-or(Get-FileHash -LiteralPath (Join-Path $release 'SHA256SUMS.txt') -Algorithm SHA256).Hash.ToLowerInvariant()-ne$a.ChecksumFileSHA256){throw 'Sealed manifest/checksum bytes changed.'}
    if((Get-NormalizedSourceSHA256 (Join-Path $Root 'deployment/production-runner.php'))-ne$a.RunnerSHA256-or(Get-NormalizedSourceSHA256 (Join-Path $Root 'app/database/MigrationRunner.php'))-ne$a.MigrationRunnerSHA256){throw 'Reviewed runner bytes changed.'}
    $versions=@(Get-ChildItem (Join-Path $Root 'database/migrations/mysql') -Filter '*.php'|Where-Object{$_.Name-match'^\d{3}_'}|ForEach-Object{[int]$_.Name.Substring(0,3)})
    if(($versions|Measure-Object -Maximum).Maximum-ne[int]$a.LatestMigration){throw 'Approved migration target changed.'}
    & (Join-Path $Root 'tools/verify-cpanel-release.ps1') -ReleasePath $release|Out-Null
    return [pscustomobject]@{ReleasePath=$release;Commit=$a.Commit;PackageSHA256=$a.PackageSHA256;PackageSize=$a.PackageSize;LatestMigration=$a.LatestMigration;RunnerSHA256=$a.RunnerSHA256;MigrationRunnerSHA256=$a.MigrationRunnerSHA256}
}
function Assert-RunnerIdentity($Status,[string]$ExpectedSHA) {
    if(-not$Status.ok-or$Status.action-ne'runner-status'-or[int]$Status.protocol_version-ne3-or$Status.build_id-ne'officeapp-deployment-v3-migrate-next-20261002'-or$Status.runner_sha256-ne$ExpectedSHA-or$Status.migration_runner_loaded-ne$false-or$Status.reference_synchronizer_loaded-ne$false){throw 'Executed runner identity/stale-class proof failed.'}
}
function Assert-StagedMigrationAudit($Audit,[string]$ExpectedSHA) {
    if(-not$Audit.ok-or$Audit.action-ne'staged-migration-audit'-or@($Audit.applied_versions).Count-eq0-or@($Audit.applied_versions)[-1]-ne'099'-or$Audit.first_unapplied-ne'100'-or$Audit.first_preflight-ne'apply'-or$Audit.migration_runner_sha256-ne$ExpectedSHA){throw 'Read-only staged migration audit failed; no database backup/migration permitted.'}
}

function Assert-ReviewedRuntimeSource([string]$Root) {
    $dirty=@(git -C $Root status --porcelain --untracked-files=no)
    if($LASTEXITCODE-ne0-or$dirty.Count){throw 'Tracked modifications cannot enter a production package.'}
    $runtime=@('app','bin','config','database','deployment','docs','public','resources','routes')
    $untracked=@(git -C $Root ls-files --others --exclude-standard -- $runtime)
    if($LASTEXITCODE-ne0-or$untracked.Count){throw 'Untracked runtime files cannot enter a production package.'}
}
function Test-RehearsalDatabaseReady([string]$Container,[string]$Client,[string]$Database='office_app_dev') {
    if($Container-notmatch'^officeapp-rehearsal-[a-f0-9]{32}-db$'-or$Client-notin@('mysql','mariadb')){throw 'Invalid isolated readiness target.'}
    if($Database-notin@('office_app_dev','passiontech_officeapp')){throw 'Invalid isolated readiness schema.'}
    # Windows PowerShell 5 treats native stderr as ErrorRecords under Stop.
    # Startup connection refusals are expected while the disposable DB initializes.
    $ErrorActionPreference='Continue'
    & docker exec $Container $Client '--protocol=tcp' '--host=127.0.0.1' '--user=root' ('--database='+$Database) '--batch' '--skip-column-names' '--execute=SELECT 1' 2>$null|Out-Null
    return ($LASTEXITCODE-eq0)
}