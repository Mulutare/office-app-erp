$ErrorActionPreference='Stop'
$root=Split-Path $PSScriptRoot
Set-Location $root
function Invoke-TestDocker { & docker @args; if($LASTEXITCODE-ne0){throw "Docker test step failed: $args"} }
$compose=@('compose','-f','compose.recruitment.test.yaml')
# This named stack uses only tmpfs synthetic test databases. No production service is addressed.
Invoke-TestDocker @compose down
Invoke-TestDocker @compose up -d
foreach($path in @('app','bin','database','resources','careers','tests','deployment','tools')) { Invoke-TestDocker @compose cp $path 'app:/var/www/html/office_app/' }
Invoke-TestDocker @compose exec -T -e RECRUITMENT_TEST_BASELINE=110 app php tests/recruitment-local-bootstrap.php
Invoke-TestDocker @compose exec -T app php tests/recruitment-module-lifecycle.php
Invoke-TestDocker @compose exec -T app php tests/careers-migration.php
Invoke-TestDocker @compose exec -T db mysql -uroot -precruitment-fixture-root-only -e "CREATE DATABASE careers_test; GRANT ALL ON careers_test.* TO 'officeapp_test'@'%';"
foreach($test in @('recruitment-migration','recruitment-integration','recruitment-endpoints','recruitment-security','recruitment-retry-integrity','careers-integration','careers-http','careers-sync','careers-source-security','recruitment-release-contract','deployment-migration-audit','deployment-runner-runtime','migration-checksum-compatibility')) { Invoke-TestDocker @compose exec -T app php "tests/$test.php" }
