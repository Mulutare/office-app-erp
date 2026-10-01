<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/bootstrap.php';
use App\Services\Recruitment\Scheduler;
if(PHP_SAPI!=='cli') { http_response_code(404); exit(1); }
if(databaseDriver()->name()!=='mysql') { fwrite(STDERR,"Recruitment requires MySQL.\n"); exit(2); }
$result=(new Scheduler())->run();
echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
exit($result['failed']?1:0);
