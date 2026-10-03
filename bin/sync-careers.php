<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/bootstrap.php';
use App\Services\Recruitment\{Repository,RecruitmentService,DocumentStore,CareersIntegrationClient,CareersSyncService};
if(PHP_SAPI!=='cli') { http_response_code(404); exit(1); }
try {
    // One public installation/key is bound to exactly one ERP company, never a browser-supplied company.
    $company=(int)getenv('CAREERS_COMPANY_ID');
    $modules=(new App\Models\CompanyModule())->enabledForCompany($company);
    if(!in_array('recruitment',array_column($modules,'code'),true)) throw new RuntimeException('Recruitment is unavailable.');
    $service=new RecruitmentService(new Repository(db(),$company),new DocumentStore());
    $client=new CareersIntegrationClient((string)getenv('CAREERS_BASE_URL'),(string)getenv('CAREERS_INTEGRATION_KEY'));
    $result=(new CareersSyncService($service,$client))->run();
    echo json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL; exit($result['failed']?1:0);
} catch(Throwable $e) { fwrite(STDERR,"Careers synchronization failed. Check configuration, license and integration availability.\n"); exit(1); }
