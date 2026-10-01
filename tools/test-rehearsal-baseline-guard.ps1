$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$prefix='officeapp-rehearsal-'+[guid]::NewGuid().ToString('N')
$dbName=$prefix+'-db';$phpName=$prefix+'-php';$fixture=$PSScriptRoot
. (Join-Path $fixture 'deployment-release-common.ps1')
$networkCreated=$false;$dbCreated=$false;$phpCreated=$false
$databaseVolumes=@()
$fixtureError=$null
try{
    & docker network create --internal $prefix|Out-Null;if($LASTEXITCODE-ne0){throw 'Cannot create isolated fixture network'};$networkCreated=$true
    & docker run --detach --name $dbName --network $prefix --network-alias db --env MYSQL_ALLOW_EMPTY_PASSWORD=yes --env MYSQL_ROOT_HOST=% --env MYSQL_DATABASE=passiontech_officeapp mysql:8.4.11|Out-Null
    if($LASTEXITCODE-ne0){throw 'Cannot create isolated fixture database'};$dbCreated=$true
    $mounts=(& docker inspect $dbName --format '{{json .Mounts}}')|ConvertFrom-Json
    if($LASTEXITCODE-ne0){throw 'Cannot record isolated fixture database volumes'}
    $databaseVolumes=@(foreach($mount in $mounts){if($mount.Type-eq'volume'){$mount.Name}})
    $ready=$false;for($i=0;$i-lt60;$i++){if(Test-RehearsalDatabaseReady $dbName mysql passiontech_officeapp){$ready=$true;break};Start-Sleep -Seconds 1};if(-not$ready){throw 'Fixture database readiness failed'}
    & docker run --detach --name $phpName --network $prefix --entrypoint sleep --mount ('type=bind,source='+$fixture+',target=/fixture,readonly') --env OFFICEAPP_REHEARSAL_FIXTURE=1 --env OFFICEAPP_REHEARSAL_ISOLATED=1 --env ('OFFICEAPP_REHEARSAL_CONTAINER='+$phpName) --env DB_HOST=db --env DB_DATABASE=passiontech_officeapp --env DB_USERNAME=root --env DB_PASSWORD= officeapp-app infinity|Out-Null
    if($LASTEXITCODE-ne0){throw 'Cannot create isolated fixture PHP container'};$phpCreated=$true
    & docker exec $phpName php /fixture/test-rehearsal-baseline-guard.php
    if($LASTEXITCODE-ne0){throw 'Baseline guard fixture failed'}
}catch{
    $fixtureError=$_
    throw
}finally{
    $cleanupErrors=New-Object 'Collections.Generic.List[string]'
    # A failed docker run can still create a named container before startup fails.
    # Discover this fixture's exact names before removal, even if its flag was not set.
    $ownedContainers=@()
    try{
        $ownedContainers=@(& docker ps --all --filter ('name='+$prefix) --format '{{.Names}}')
        if($LASTEXITCODE-ne0){throw 'Cannot discover fixture containers for cleanup'}
    }catch{$cleanupErrors.Add($_.Exception.Message)}
    if(($dbCreated-or$dbName-in$ownedContainers)-and-not$databaseVolumes.Count){
        try{
            $mounts=(& docker inspect $dbName --format '{{json .Mounts}}')|ConvertFrom-Json
            if($LASTEXITCODE-ne0){throw 'Cannot record fixture database volumes during cleanup'}
            $databaseVolumes=@(foreach($mount in $mounts){if($mount.Type-eq'volume'){$mount.Name}})
        }catch{$cleanupErrors.Add($_.Exception.Message)}
    }
    $ownedNetwork=$networkCreated
    try{
        $networks=@(& docker network ls --filter ('name='+$prefix) --format '{{.Name}}')
        if($LASTEXITCODE-ne0){throw 'Cannot discover fixture network for cleanup'}
        $ownedNetwork=$ownedNetwork-or$prefix-in$networks
    }catch{$cleanupErrors.Add($_.Exception.Message)}
    foreach($entry in @(@{Created=$phpCreated;Name=$phpName},@{Created=$dbCreated;Name=$dbName})){
        if($entry.Created-or$entry.Name-in$ownedContainers){
            try{
                & docker rm --force --volumes $entry.Name|Out-Null
                if($LASTEXITCODE-ne0){throw ('Fixture container removal failed: '+$entry.Name)}
            }catch{$cleanupErrors.Add($_.Exception.Message)}
        }
    }
    if($ownedNetwork){
        try{
            & docker network rm $prefix|Out-Null
            if($LASTEXITCODE-ne0){throw 'Fixture network removal failed'}
        }catch{$cleanupErrors.Add($_.Exception.Message)}
    }
    if($networkCreated-or$dbCreated-or$phpCreated-or$ownedContainers.Count){
        try{
            $remaining=@(& docker ps --all --filter ('name='+$prefix) --format '{{.Names}}')
            if($LASTEXITCODE-ne0-or$remaining.Count){throw 'Fixture containers remain or cannot be checked'}
        }catch{$cleanupErrors.Add($_.Exception.Message)}
    }
    if($ownedNetwork){
        try{
            $remaining=@(& docker network ls --filter ('name='+$prefix) --format '{{.Name}}')
            if($LASTEXITCODE-ne0-or$remaining.Count){throw 'Fixture network remains or cannot be checked'}
        }catch{$cleanupErrors.Add($_.Exception.Message)}
    }
    if($databaseVolumes.Count){
        try{
            $volumes=@(& docker volume ls --format '{{.Name}}')
            if($LASTEXITCODE-ne0){throw 'Cannot verify fixture database volumes'}
            foreach($volume in $databaseVolumes){if($volume-in$volumes){throw ('Fixture database volume remains: '+$volume)}}
        }catch{$cleanupErrors.Add($_.Exception.Message)}
    }
    if($cleanupErrors.Count){
        $message='Fixture cleanup failed: '+($cleanupErrors.ToArray()-join'; ')
        if($null-ne$fixtureError){$message+='; primary fixture failure: '+$fixtureError.Exception.Message}
        throw $message
    }
}
