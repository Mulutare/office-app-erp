[CmdletBinding()]
param([Parameter(Mandatory=$true)][string]$BackupPath,[switch]$KeepFailedEnvironment,[switch]$DiagnoseBaselineOnly,[string]$RepositoryRoot,[string]$ReportDirectory,[string]$ProductionEnvironmentPath)
$ErrorActionPreference='Stop';Set-StrictMode -Version Latest
. (Join-Path $PSScriptRoot 'deployment-release-common.ps1')
. (Join-Path $PSScriptRoot 'rehearsal-dump-audit.ps1')
if(-not$RepositoryRoot){$RepositoryRoot=Join-Path $PSScriptRoot '..'}
$root=(Resolve-Path -LiteralPath $RepositoryRoot).Path
$backup=(Resolve-Path -LiteralPath $BackupPath).Path
if((Get-Item -LiteralPath $backup).Length-le0){throw 'Backup is empty.'}
$id=[guid]::NewGuid().ToString('N');$network='officeapp-rehearsal-'+$id;$dbContainer=$network+'-db';$phpContainer=$network+'-php'
$temp=Join-Path ([IO.Path]::GetTempPath()) $network;$source=Join-Path $temp 'source';$reportDir=if($ReportDirectory){[IO.Path]::GetFullPath($ReportDirectory)}else{Join-Path $root ('dist/rehearsal/'+$id)}
$success=$false;$createdNetwork=$false;$createdDB=$false;$createdPHP=$false;$createdDeps=$false;$createdDepsImage=$false;$depsContainer=$network+'-deps'
$report=[ordered]@{Result='FAIL';BackupSHA256=(Get-FileHash -LiteralPath $backup -Algorithm SHA256).Hash.ToLowerInvariant();DatabaseImage='mariadb:10.11.18';DumpMetadata=@();Tests=@();Isolation=@{Network=$network;PublishedPorts=@();ProductionCredentialsUsed=$false};Error=$null}
function Docker-Checked([string[]]$Arguments){& docker @Arguments;if($LASTEXITCODE-ne0){throw "Isolated Docker operation failed: $($Arguments[0])"}}
try {
    $productionEnvironment=$null
    if(-not$DiagnoseBaselineOnly){
        if(-not$ProductionEnvironmentPath){throw 'Production server/database/session metadata is required before an upgrade rehearsal. Use -DiagnoseBaselineOnly for read-only diagnosis.'}
        $productionEnvironment=Get-Content -LiteralPath $ProductionEnvironmentPath -Raw|ConvertFrom-Json
        foreach($field in @('version','version_comment','character_set_server','collation_server','character_set_database','collation_database','character_set_client','character_set_connection','character_set_results','collation_connection')){
            if($null-eq$productionEnvironment.PSObject.Properties[$field]){throw "Missing production metadata: $field"}
        }
        foreach($field in @('character_set_server','collation_server','character_set_database','collation_database','character_set_client','character_set_connection','character_set_results','collation_connection')){
            if([string]$productionEnvironment.$field-notmatch'^[a-zA-Z0-9_]+$'){throw "Malformed production metadata: $field"}
        }
        if($null-ne$productionEnvironment.PSObject.Properties['sql_mode']-and[string]$productionEnvironment.sql_mode-notmatch'^[A-Z0-9_,]*$'){throw 'Malformed production sql_mode'}
        if($null-eq$productionEnvironment.PSObject.Properties['required_sql_modes']-or'ONLY_FULL_GROUP_BY'-notin@($productionEnvironment.required_sql_modes)){throw 'Production SQL-mode evidence must require ONLY_FULL_GROUP_BY'}
        foreach($mode in @($productionEnvironment.required_sql_modes)){if([string]$mode-notmatch'^[A-Z0-9_]+$'){throw 'Malformed required production SQL mode'}}
        $report.ProductionEnvironment=$productionEnvironment
    }
    $report.BackupAudit=Get-RehearsalDumpAudit $backup
    New-Item -ItemType Directory -Force $temp,$source,$reportDir|Out-Null
    $dump=Join-Path $temp 'restore.sql'
    $inputStream=[IO.File]::OpenRead($backup);$gzip=New-Object IO.Compression.GZipStream($inputStream,[IO.Compression.CompressionMode]::Decompress);$outputStream=[IO.File]::Create($dump)
    try{$gzip.CopyTo($outputStream)}finally{$outputStream.Dispose();$gzip.Dispose();$inputStream.Dispose()}
    if((Get-Item -LiteralPath $dump).Length-le0){throw 'Decompressed backup is empty.'}
    $header=@([IO.File]::ReadLines($dump)|Select-Object -First 40)
    $report.DumpMetadata=@($header|Where-Object{$_-match'^-- (MariaDB dump|MySQL dump|Server version)'}|ForEach-Object{$_.ToString()})
    $serverLine=@($report.DumpMetadata|Where-Object{$_-match'^-- Server version'})
    if($serverLine.Count-ne1){throw 'Exactly one server metadata header is required.'}
    $client='mariadb';$admin='mariadb-admin';$dbEnvironment=@('MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=yes','MARIADB_ROOT_HOST=%','MARIADB_DATABASE=passiontech_officeapp')
    if($serverLine[0]-match'8\.4\.11(?:-cll-lve)?$'){
        $report.DatabaseImage='mysql:8.4.11';$client='mysql';$admin='mysqladmin';$dbEnvironment=@('MYSQL_ALLOW_EMPTY_PASSWORD=yes','MYSQL_ROOT_HOST=%','MYSQL_DATABASE=passiontech_officeapp')
    }elseif($serverLine[0]-notmatch'10\.11\..*MariaDB'){
        throw 'Dump requires a different explicitly reviewed compatible database image.'
    }
    if($productionEnvironment-and[string]$productionEnvironment.version-notmatch'^8\.4\.11(?:-cll-lve)?$'){throw 'Production metadata does not match the reviewed source engine.'}
    # Copy reviewed checkout source only, excluding every ignored config/secret.
    $tracked=@(git -C $root ls-files)
    if($LASTEXITCODE-ne0){throw 'Cannot enumerate reviewed source.'}
    $newReviewed=@('deployment/powerbi-upgrade-validation.php','tests/powerbi-mysql84-readiness-contract.php','tools/rehearsal-post-tests-audit.php','tools/rehearse-production-upgrade.php','tests/deployment-migration-audit.php','tools/deployment-release-common.ps1','tools/test-production-deployment.ps1','tools/rehearse-production-upgrade.ps1','tools/rehearsal-dump-audit.ps1','tools/rehearsal-baseline-audit.php','tools/rehearsal-test-session.php','tools/test-rehearsal-baseline-guard.php','tools/test-rehearsal-baseline-guard.ps1')
    $catalog=@(Get-ChildItem -LiteralPath (Join-Path $root 'database/migrations/mysql') -File -Filter '*.php'|Where-Object{$_.Name-match'^\d{3}_'}|ForEach-Object{[int]$_.Name.Substring(0,3)}|Sort-Object)
    if($catalog.Count-ne95-or($catalog-join',')-ne((15..109)-join',')){throw 'Reviewed release must contain exactly migrations 015-109.'}
    foreach($relative in @($tracked+$newReviewed|Sort-Object -Unique)){
        if($relative-match'^(\.deploy/|storage/|work/|dist/|artifacts/)'-or$relative-in@('config/database.php','config/app.local.php')){continue}
        $from=Join-Path $root $relative;if(-not(Test-Path -LiteralPath $from -PathType Leaf)){continue}
        $to=Join-Path $source $relative;New-Item -ItemType Directory -Force (Split-Path $to)|Out-Null;Copy-Item -LiteralPath $from -Destination $to
    }
    foreach($name in @('rehearse-production-upgrade.php','rehearsal-baseline-audit.php','rehearsal-test-session.php')){Copy-Item -LiteralPath (Join-Path $PSScriptRoot $name) -Destination (Join-Path $source ('tools/'+$name))}
    # Build dependencies from the reviewed Composer lock; the runtime image supplies PHP extensions only.
    Docker-Checked @('build','--target','php-dependencies','--tag',($network+'-deps'),'--file',(Join-Path $root 'Dockerfile'),$root);$createdDepsImage=$true
    Docker-Checked @('create','--name',$depsContainer,($network+'-deps'));$createdDeps=$true
    Docker-Checked @('cp',($depsContainer+':/app/vendor'),$source)
    Docker-Checked @('image','inspect','officeapp-app','--format','{{.Id}}')
    Docker-Checked @('network','create','--internal',$network);$createdNetwork=$true
    $dbArgs=@('run','--detach','--name',$dbContainer,'--network',$network,'--network-alias','db');foreach($value in $dbEnvironment){$dbArgs+=@('--env',$value)};$dbArgs+=$report.DatabaseImage
    if($productionEnvironment){
        $dbArgs+=@(('--character-set-server='+$productionEnvironment.character_set_server),('--collation-server='+$productionEnvironment.collation_server))
        if($null-ne$productionEnvironment.PSObject.Properties['sql_mode']){$dbArgs+=('--sql-mode='+$productionEnvironment.sql_mode)}
    }
    Docker-Checked $dbArgs;$createdDB=$true
    $report.ActualDatabaseImage=(& docker image inspect $report.DatabaseImage --format '{{json .}}'|ConvertFrom-Json|Select-Object Id,RepoDigests)
    $mounts=(& docker inspect $dbContainer --format '{{json .Mounts}}')|ConvertFrom-Json
    $report.Isolation.DatabaseVolumes=@(foreach($mount in $mounts){if($mount.Type-eq'volume'){$mount.Name}})
    $ready=$false
    for($i=0;$i-lt60;$i++){if(Test-RehearsalDatabaseReady $dbContainer $client passiontech_officeapp){$ready=$true;break};Start-Sleep -Seconds 1}
    if(-not$ready){throw 'Isolated database did not become ready.'}
    $credentials=Join-Path $temp 'disposable-definers.sql'
    $accountSQL=@("SET SESSION sql_mode='NO_BACKSLASH_ESCAPES';")
    if($productionEnvironment){$accountSQL+=('ALTER DATABASE `passiontech_officeapp` CHARACTER SET '+$productionEnvironment.character_set_database+' COLLATE '+$productionEnvironment.collation_database+';')}
    foreach($account in $report.BackupAudit.Accounts){
        $user=$account.User.Replace("'","''");$hostName=$account.Host.Replace("'","''")
        $bytes=New-Object byte[] 32;$rng=[Security.Cryptography.RandomNumberGenerator]::Create();try{$rng.GetBytes($bytes)}finally{$rng.Dispose()}
        $password=[Convert]::ToBase64String($bytes)
        $accountSQL+="CREATE USER '$user'@'$hostName' IDENTIFIED BY '$password';"
        $accountSQL+="GRANT ALL PRIVILEGES ON ``passiontech_officeapp``.* TO '$user'@'$hostName';"
    }
    [IO.File]::WriteAllLines($credentials,$accountSQL,(New-Object Text.UTF8Encoding($false)))
    $password=$null;$accountSQL=$null;$bytes=$null
    Docker-Checked @('cp',$credentials,($dbContainer+':/tmp/disposable-definers.sql'))
    Docker-Checked @('exec',$dbContainer,'sh','-c',($client+' -uroot < /tmp/disposable-definers.sql'))
    Docker-Checked @('cp',$dump,($dbContainer+':/tmp/restore.sql'))
    $restorePrefix=if($productionEnvironment){'SET NAMES '+$productionEnvironment.character_set_connection+' COLLATE '+$productionEnvironment.collation_connection+'; SET collation_connection='+$productionEnvironment.collation_connection+';'}else{''}
    # Initialize only this import session, then stream the original dump unchanged.
    # Every explicit session directive in the dump takes precedence as it is read.
    Docker-Checked @('exec',$dbContainer,'sh','-c',("{ printf '%s\n' '$restorePrefix'; cat /tmp/restore.sql; } | "+$client+' -uroot passiontech_officeapp'))
    $report.Restore='PASS'
    $phpArgs=@('run','--detach','--name',$phpContainer,'--network',$network,'--entrypoint','sleep','--mount',('type=bind,source='+$source+',target=/var/www/html/office_app'),'--mount',('type=bind,source='+$reportDir+',target=/rehearsal-report'),'--env','DB_DRIVER=mysql','--env','DB_HOST=db','--env','DB_DATABASE=passiontech_officeapp','--env','DB_USERNAME=root','--env','DB_PASSWORD=','--env',('OFFICEAPP_REHEARSAL_CONTAINER='+$phpContainer),'--env','OFFICEAPP_REHEARSAL_ISOLATED=1','--env',('OFFICEAPP_REHEARSAL_DIAGNOSTIC_ONLY='+[int][bool]$DiagnoseBaselineOnly),'--env','APP_ENV=development','officeapp-app','infinity')
    if($productionEnvironment){$productionEnvironment|ConvertTo-Json -Depth 4|Set-Content -LiteralPath (Join-Path $source 'tools/rehearsal-expected-environment.json') -Encoding utf8}
    Docker-Checked $phpArgs;$createdPHP=$true
    Docker-Checked @('exec',$phpContainer,'php','tools/rehearse-production-upgrade.php')
    $upgrade=Get-Content -Raw (Join-Path $reportDir 'upgrade.json')|ConvertFrom-Json
    if($DiagnoseBaselineOnly){throw 'Diagnostic-only run completed; upgrade/release steps prohibited.'}
    if($upgrade.result-ne'PASS'){throw 'Upgrade invariants failed.'}
    $report.Upgrade=$upgrade
    foreach($test in @('tests/deployment-migration-audit.php','tests/migration-checksum-compatibility.php','tests/powerbi-mysql84-readiness-contract.php','tests/powerbi-live-capture-contract.php','tests/powerbi-live-capture-write-smoke.php','tests/powerbi-live-bank-capture-write-smoke.php','tests/powerbi-explicit-shop-scope-contract.php')){
        $output=@(& docker exec $phpContainer php -d auto_prepend_file=tools/rehearsal-test-session.php $test 2>&1);$code=$LASTEXITCODE
        $output|Set-Content -LiteralPath (Join-Path $reportDir ((Split-Path $test -Leaf)+'.txt')) -Encoding utf8
        $summary=($output|Where-Object{$_-match'\d+ (?:migration checksum checks|deployment audit checks|readiness checks|checks), \d+ failures'}|Select-Object -Last 1)
        $report.Tests+=@{File=$test;ExitCode=$code;Summary=[string]$summary}
        $output|Write-Output
        if($code-ne0){throw "Isolated focused test failed: $test"}
    }
    Docker-Checked @('exec',$phpContainer,'php','tools/rehearsal-post-tests-audit.php')
    $report.PostTestsAudit=Get-Content -Raw (Join-Path $reportDir 'post-tests-audit.json')|ConvertFrom-Json
    if($report.PostTestsAudit.result-ne'PASS'){throw 'Post-test production-clone invariants failed'}
    $report.Result='PASS';$success=$true
} catch {$report.Error=$_.Exception.Message;Write-Warning $report.Error}
finally {
    $cleanupErrors=New-Object 'Collections.Generic.List[string]'
    if(Test-Path -LiteralPath (Join-Path $reportDir 'upgrade.json')){
        try{$report.Upgrade=Get-Content -Raw (Join-Path $reportDir 'upgrade.json')|ConvertFrom-Json}catch{$cleanupErrors.Add('Cannot read upgrade evidence: '+$_.Exception.Message)}
    }
    # Plaintext and generated credential files are never retained for debugging.
    foreach($leaf in @('restore.sql','disposable-definers.sql')){
        $sensitive=Join-Path $temp $leaf
        try{if(Test-Path -LiteralPath $sensitive){Remove-Item -LiteralPath $sensitive -Force}}catch{$cleanupErrors.Add('Sensitive-file cleanup failed: '+$sensitive+'; '+$_.Exception.Message)}
    }
    # Docker can create a resource before a failed start returns nonzero.
    # Discover only this attempt's exact GUID-owned names before removing them.
    $ownedContainers=@();$ownedNetwork=$createdNetwork
    try{
        $discoveredContainers=@(& docker ps --all --filter ('name='+$network) --format '{{.Names}}')
        if($LASTEXITCODE-ne0){throw 'Cannot discover rehearsal containers for cleanup'}
        $ownedContainers=@($discoveredContainers|Where-Object{$_-in@($depsContainer,$phpContainer,$dbContainer)})
    }catch{$cleanupErrors.Add($_.Exception.Message)}
    try{
        $networks=@(& docker network ls --filter ('name='+$network) --format '{{.Name}}')
        if($LASTEXITCODE-ne0){throw 'Cannot discover rehearsal network for cleanup'}
        $ownedNetwork=$ownedNetwork-or$network-in$networks
    }catch{$cleanupErrors.Add($_.Exception.Message)}
    $ownedDB=$createdDB-or$dbContainer-in$ownedContainers
    if($ownedDB-and-not$report.Isolation.ContainsKey('DatabaseVolumes')){
        try{
            $mounts=(& docker inspect $dbContainer --format '{{json .Mounts}}')|ConvertFrom-Json
            if($LASTEXITCODE-ne0){throw 'Cannot record rehearsal database volumes during cleanup'}
            $report.Isolation.DatabaseVolumes=@(foreach($mount in $mounts){if($mount.Type-eq'volume'){$mount.Name}})
        }catch{$cleanupErrors.Add($_.Exception.Message)}
    }
    if($ownedDB){try{& docker exec $dbContainer sh -c 'rm -f /tmp/restore.sql /tmp/disposable-definers.sql'|Out-Null;if($LASTEXITCODE-ne0){throw 'docker exec cleanup failed'}}catch{$cleanupErrors.Add($_.Exception.Message)}}
    if($success-or-not$KeepFailedEnvironment){
        foreach($entry in @(@{Created=$createdDeps;Name=$depsContainer},@{Created=$createdPHP;Name=$phpContainer},@{Created=$createdDB;Name=$dbContainer})){
            if($entry.Created-or$entry.Name-in$ownedContainers){try{& docker rm --force --volumes $entry.Name|Out-Null;if($LASTEXITCODE-ne0){throw ('Container removal failed: '+$entry.Name)}}catch{$cleanupErrors.Add($_.Exception.Message)}}
        }
        if($ownedNetwork){try{& docker network rm $network|Out-Null;if($LASTEXITCODE-ne0){throw 'Network removal failed'}}catch{$cleanupErrors.Add($_.Exception.Message)}}
        if($createdDepsImage){try{& docker image rm ($network+'-deps')|Out-Null;if($LASTEXITCODE-ne0){throw 'Temporary dependency image removal failed'}}catch{$cleanupErrors.Add($_.Exception.Message)}}
        try{
            if([IO.Path]::GetFullPath($temp)-ne[IO.Path]::GetFullPath((Join-Path ([IO.Path]::GetTempPath()) $network))){throw 'Temporary cleanup target mismatch'}
            if(Test-Path -LiteralPath $temp){Remove-Item -LiteralPath $temp -Recurse -Force}
            if(Test-Path -LiteralPath $temp){throw 'Temporary rehearsal directory remains'}
        }catch{$cleanupErrors.Add($_.Exception.Message)}
        if($createdDB-or$createdPHP-or$createdDeps-or$ownedContainers.Count){try{$remaining=@(& docker ps --all --filter ('name='+$network) --format '{{.Names}}');if($LASTEXITCODE-ne0-or$remaining.Count){throw 'Rehearsal containers remain or cannot be checked'}}catch{$cleanupErrors.Add($_.Exception.Message)}}
        if($ownedNetwork){try{$remaining=@(& docker network ls --filter ('name='+$network) --format '{{.Name}}');if($LASTEXITCODE-ne0-or$remaining.Count){throw 'Rehearsal network remains or cannot be checked'}}catch{$cleanupErrors.Add($_.Exception.Message)}}
        if($report.Isolation.ContainsKey('DatabaseVolumes')){try{$volumes=@(& docker volume ls --format '{{.Name}}');if($LASTEXITCODE-ne0){throw 'Cannot verify temporary volumes'};foreach($volume in $report.Isolation.DatabaseVolumes){if($volume-in$volumes){throw ('Temporary database volume remains: '+$volume)}}}catch{$cleanupErrors.Add($_.Exception.Message)}}
    } else {$report.Isolation.KeptForDebugging=$true}
    $report.Cleanup=@{Errors=@($cleanupErrors.ToArray());TemporaryDirectoryExists=(Test-Path -LiteralPath $temp);SensitiveFilesRemain=@(@('restore.sql','disposable-definers.sql')|Where-Object{Test-Path -LiteralPath (Join-Path $temp $_)})}
    if($cleanupErrors.Count){$success=$false;$report.Result='FAIL';$report.Cleanup.Result='FAIL'}else{$report.Cleanup.Result='PASS'}
    New-Item -ItemType Directory -Force $reportDir|Out-Null
    $report|ConvertTo-Json -Depth 16|Set-Content -LiteralPath (Join-Path $reportDir 'rehearsal.json') -Encoding utf8
    $lines=@('# Production-backup upgrade rehearsal','',"Result: $($report.Result)","Backup SHA256: $($report.BackupSHA256)","Image: $($report.DatabaseImage)",('Dump: '+($report.DumpMetadata-join'; ')),'','The environment uses an internal disposable Docker network with no published ports or production credentials.','')
    if($report.Contains('Upgrade')){$lines+=@('Upgrade evidence:','```json',($report.Upgrade|ConvertTo-Json -Depth 10),'```')}
    $lines+=@('','Focused tests:');foreach($test in $report.Tests){$lines+="- $($test.File): exit $($test.ExitCode); $($test.Summary)"};if($report.Error){$lines+="Error: $($report.Error)"}
    $lines|Set-Content -LiteralPath (Join-Path $reportDir 'rehearsal.md') -Encoding utf8
}
Write-Output "Rehearsal report: $reportDir"
if(-not$success){exit 1}
