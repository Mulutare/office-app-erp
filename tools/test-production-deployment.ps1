[CmdletBinding()]
param()
$ErrorActionPreference='Stop';Set-StrictMode -Version Latest
. (Join-Path $PSScriptRoot 'deployment-release-common.ps1')
$passed=0;$failed=0
function Check([bool]$OK,[string]$Name){if($OK){$script:passed++;Write-Output "PASS $Name"}else{$script:failed++;Write-Output "FAIL $Name"}}
function Reject([scriptblock]$Action){try{& $Action|Out-Null;return $false}catch{return $true}}
$fixture=Join-Path ([IO.Path]::GetTempPath()) ('officeapp-seal-test-'+[guid]::NewGuid().ToString('N'))
try {
$commit='1234567890abcdef1234567890abcdef12345678';$releasePath=Join-Path $fixture 'dist/releases/1234567'
foreach($dir in @($releasePath,(Join-Path $fixture 'deployment'),(Join-Path $fixture 'app/database'),(Join-Path $fixture 'database/migrations/mysql'),(Join-Path $fixture 'tools'))){New-Item -ItemType Directory -Force $dir|Out-Null}
Set-Content (Join-Path $fixture 'deployment/production-runner.php') 'runner';Set-Content (Join-Path $fixture 'app/database/MigrationRunner.php') 'migration runner';Set-Content (Join-Path $fixture 'database/migrations/mysql/109_fixture.php') 'fixture'
Set-Content (Join-Path $fixture 'tools/verify-cpanel-release.ps1') "param([string]`$ReleasePath);[pscustomobject]@{Validation='PASS'}"
$release=[pscustomobject]@{ReleasePath=$releasePath;LatestMigration=109}
$package=Join-Path $releasePath 'officeapp-cpanel.tar.gz';$manifest=Join-Path $releasePath 'deployment-manifest.txt'
Set-Content $package 'sealed package';Set-Content $manifest 'sealed manifest';Set-Content (Join-Path $releasePath 'SHA256SUMS.txt') 'checksum file'
Check (Reject {Read-SealedRelease $fixture $commit $commit $false}) 'Execute refuses an unapproved artifact'
$a=Write-SealedRelease $fixture $release $commit
Check ((Read-SealedRelease $fixture $commit $commit $false).PackageSHA256-eq$a.PackageSHA256) 'Exact approved artifact accepted without building'
Check (Reject {Read-SealedRelease $fixture ('f'*40) $commit $false}) 'Changed HEAD rejected'
Check (Reject {Read-SealedRelease $fixture $commit ('f'*40) $false}) 'Divergent origin rejected'
Check (Reject {Read-SealedRelease $fixture $commit $commit $true}) 'Dirty tracked tree rejected'
Add-Content $package 'changed';Check (Reject {Read-SealedRelease $fixture $commit $commit $false}) 'Altered package rejected';Set-Content $package 'sealed package'
Add-Content $manifest 'changed';Check (Reject {Read-SealedRelease $fixture $commit $commit $false}) 'Altered manifest rejected';Set-Content $manifest 'sealed manifest'
Add-Content (Join-Path $fixture 'deployment/production-runner.php') 'changed';Check (Reject {Read-SealedRelease $fixture $commit $commit $false}) 'Altered local runner rejected';Set-Content (Join-Path $fixture 'deployment/production-runner.php') 'runner'
$identity=[pscustomobject]@{ok=$true;action='runner-status';protocol_version=3;build_id='officeapp-deployment-v3-sealed-audit-20261001';runner_sha256='expected';migration_runner_loaded=$false;reference_synchronizer_loaded=$false}
Check (-not(Reject {Assert-RunnerIdentity $identity 'expected'})) 'Exact v3 executed identity accepted'
$identity.migration_runner_loaded=$true;Check (Reject {Assert-RunnerIdentity $identity 'expected'}) 'Loaded stale migration class rejected';$identity.migration_runner_loaded=$false
$identity.reference_synchronizer_loaded=$true;Check (Reject {Assert-RunnerIdentity $identity 'expected'}) 'Loaded stale reference class rejected';$identity.reference_synchronizer_loaded=$false
$identity.protocol_version=2;Check (Reject {Assert-RunnerIdentity $identity 'expected'}) 'Old compiled protocol rejected';$identity.protocol_version=3
Check (Reject {Assert-RunnerIdentity $identity 'different'}) 'Wrong executed runner SHA rejected'
$audit=[pscustomobject]@{ok=$true;action='staged-migration-audit';applied_versions=@('015','099');first_unapplied='100';first_preflight='apply';migration_runner_sha256='expected'}
Check (-not(Reject {Assert-StagedMigrationAudit $audit 'expected'})) '099/100/apply staged audit accepted'
$audit.first_preflight='baseline';Check (Reject {Assert-StagedMigrationAudit $audit 'expected'}) 'Unexpected staged preflight rejected'
# Simulate Windows PowerShell WebException HTTP 500 JSON ErrorDetails without networking.
Add-Type -TypeDefinition 'public class DeploymentHttpTestException:System.Exception { public object Response {get;set;} }'
$exception=New-Object DeploymentHttpTestException;$exception.Response=[pscustomobject]@{StatusCode=500}
$errorRecord=New-Object Management.Automation.ErrorRecord($exception,'test',[Management.Automation.ErrorCategory]::InvalidOperation,$null)
$errorRecord.ErrorDetails=New-Object Management.Automation.ErrorDetails('{"error":"deployment_action_failed","message":"Exact staged SQL failure"}')
$message=Format-RunnerHttpFailure 'migrate' $errorRecord
Check ($message-eq'Runner action=migrate HTTP=500 error=deployment_action_failed message=Exact staged SQL failure') 'HTTP 500 JSON code/message retained'
$project=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$deploy=Get-Content -Raw (Join-Path $project 'tools/deploy-production.ps1')
Check ($deploy.Contains('if($Execute){$release=Read-SealedRelease')-and$deploy.Contains('}else{$release=&')) 'Execute uses seal branch and never rebuilds'
Check ($deploy.IndexOf("Runner 'runner-status'")-lt$deploy.IndexOf("Runner 'begin-release'")) 'Identity proof precedes remote writes'
Check ($deploy.IndexOf("Runner 'staged-migration-audit'")-lt$deploy.IndexOf("Runner 'database-backup'")) 'Staged audit precedes database backup/migration'
$build=Get-Content -Raw (Join-Path $project 'tools/build-cpanel-package.ps1')
Check ($build.Contains('git -C $projectRoot archive')-and$build.Contains('Assert-ReviewedRuntimeSource')) 'Package uses reviewed HEAD and rejects dirty/untracked runtime source'
$gitFixture=Join-Path $fixture 'source-test';New-Item -ItemType Directory -Force (Join-Path $gitFixture 'app')|Out-Null
Set-Content (Join-Path $gitFixture 'app/reviewed.php') 'reviewed';Set-Content (Join-Path $gitFixture '.gitignore') 'app/local-secret.php'
git -C $gitFixture init --quiet
if($LASTEXITCODE-ne0){throw 'Fixture git init failed'}
git -C $gitFixture add -- app/reviewed.php .gitignore
git -C $gitFixture -c user.name=DeploymentTest -c user.email=deployment-test@example.invalid commit --quiet -m fixture
if($LASTEXITCODE-ne0){throw 'Fixture git commit failed'}
Check (-not(Reject {Assert-ReviewedRuntimeSource $gitFixture})) 'Clean tracked runtime source accepted'
Set-Content (Join-Path $gitFixture 'app/unreviewed.php') 'unreviewed'
Check (Reject {Assert-ReviewedRuntimeSource $gitFixture}) 'Actual untracked runtime file rejected'
Remove-Item -LiteralPath (Join-Path $gitFixture 'app/unreviewed.php')
Add-Content (Join-Path $gitFixture 'app/reviewed.php') 'changed'
Check (Reject {Assert-ReviewedRuntimeSource $gitFixture}) 'Actual tracked modification rejected'
git -C $gitFixture checkout -- app/reviewed.php
Set-Content (Join-Path $gitFixture 'app/local-secret.php') 'local only'
$trackedArchive=Join-Path $fixture 'tracked.tar'
git -C $gitFixture archive --format=tar --output=$trackedArchive HEAD -- app
$entries=@(tar -tf $trackedArchive)
Check ($entries-contains'app/reviewed.php'-and$entries-notcontains'app/local-secret.php') 'Ignored secrets cannot enter tracked HEAD archive'
function docker {Write-Error 'Simulated startup connection refused';$global:LASTEXITCODE=1}
Check (-not(Test-RehearsalDatabaseReady ('officeapp-rehearsal-'+('a'*32)+'-db') 'mysql')) 'Expected native startup stderr does not terminate readiness polling'
function docker {$global:LASTEXITCODE=0}
Check (Test-RehearsalDatabaseReady ('officeapp-rehearsal-'+('a'*32)+'-db') 'mysql') 'Ready isolated database completes polling'
Check (Reject {Test-RehearsalDatabaseReady 'officeapp-db-1' 'mysql'}) 'Readiness cannot target the normal development database container'
} catch {$failed++;Write-Output "FAIL $($_.Exception.Message)"}
finally {if((Test-Path -LiteralPath $fixture)-and$fixture.StartsWith([IO.Path]::GetTempPath(),[StringComparison]::OrdinalIgnoreCase)){Remove-Item -LiteralPath $fixture -Recurse -Force}}
Write-Output "$($passed+$failed) deployment checks, $failed failures"
if($failed){exit 1}
