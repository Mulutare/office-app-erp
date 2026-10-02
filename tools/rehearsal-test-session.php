<?php
declare(strict_types=1);
if(getenv('OFFICEAPP_REHEARSAL_ISOLATED')!=='1'||getenv('DB_HOST')!=='db'||getenv('DB_DATABASE')!=='passiontech_officeapp'||getenv('DB_USERNAME')!=='root'||(string)getenv('DB_PASSWORD')!==''||preg_match('/^officeapp-rehearsal-[a-f0-9]{32}-php$/',(string)getenv('OFFICEAPP_REHEARSAL_CONTAINER'))!==1){fwrite(STDERR,'Focused test isolation rejected before opening any connection'.PHP_EOL);exit(1);}
require_once __DIR__.'/../app/helpers/bootstrap.php';
require_once __DIR__.'/rehearsal-baseline-audit.php';
$profile=json_decode(ltrim((string)file_get_contents(__DIR__.'/rehearsal-expected-environment.json'),"\xef\xbb\xbf"),true,512,JSON_THROW_ON_ERROR);
if(!is_array($profile)||$profile===[])throw new RuntimeException('Proven production session metadata required for focused tests');
$connection=db();
if($connection->query('SELECT DATABASE()')->fetchColumn()!=='passiontech_officeapp')throw new RuntimeException('Focused test schema identity mismatch');
initializeRehearsalSession($connection,$profile);
require_once __DIR__.'/../deployment/powerbi-upgrade-validation.php';
\OfficeApp\Deployment\initializePowerBiUpgradeSession($connection);
