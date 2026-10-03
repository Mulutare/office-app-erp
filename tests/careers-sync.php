<?php
declare(strict_types=1);
require __DIR__.'/../app/helpers/bootstrap.php';require __DIR__.'/../careers/src/bootstrap.php';
use App\Services\Recruitment\{Repository,RecruitmentService,DocumentStore,CareersPublicationService,CareersIntegrationClient,CareersSyncService};
if(getenv('APP_ENV')!=='testing'||getenv('DB_DATABASE')!=='office_app_test')throw new RuntimeException('Disposable database required.');
$checks=0;$check=function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;echo "PASS $label\n";};
$reject=static function($call){try{$call();return false;}catch(Throwable $e){return true;}};
$dir=sys_get_temp_dir().'/careers-tls-'.bin2hex(random_bytes(8));mkdir($dir,0700);
file_put_contents($dir.'/openssl.cnf',"[req]\ndistinguished_name=dn\nx509_extensions=ext\nprompt=no\n[dn]\nCN=localhost\n[ext]\nsubjectAltName=DNS:localhost\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\n");
$key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
$csr=openssl_csr_new(['commonName'=>'localhost'],$key,['config'=>$dir.'/openssl.cnf']);
$cert=openssl_csr_sign($csr,null,$key,1,['config'=>$dir.'/openssl.cnf','x509_extensions'=>'ext']);
openssl_pkey_export_to_file($key,$dir.'/key.pem');openssl_x509_export_to_file($cert,$dir.'/cert.pem');
$process=proc_open([PHP_BINARY,__DIR__.'/careers-tls-server.php',$dir.'/cert.pem',$dir.'/key.pem',$dir],[0=>['pipe','r'],1=>['file',$dir.'/server.log','a'],2=>['file',$dir.'/server.log','a']],$pipes);
try {
 usleep(400000);$secret=str_repeat('synthetic-careers-only-',3);
 $check($reject(fn()=>(new CareersIntegrationClient('https://localhost',$secret))->request('pending',['limit'=>20])),'Real cURL rejects untrusted TLS certificate');
 $client=new CareersIntegrationClient('https://localhost',$secret,$dir.'/cert.pem');
 $check(isset($client->request('pending',['limit'=>20])['ids']),'Real cURL verifies trusted TLS certificate and signs request');
 $check($reject(fn()=>(new CareersIntegrationClient('https://localhost',str_repeat('wrong-key',8),$dir.'/cert.pem'))->request('pending',['limit'=>20])),'Real HTTPS integration rejects wrong dedicated key');
 $company=(int)db()->query("SELECT company_id FROM companies WHERE code='default'")->fetchColumn();
 $repo=new Repository(db(),$company);$service=new RecruitmentService($repo,new DocumentStore());$publish=new CareersPublicationService($repo);
 $vacancy=$service->vacancy(['reference'=>'TLS-'.bin2hex(random_bytes(4)),'title'=>'TLS integration vacancy','status'=>'open'],null);
 $publish->saveCriterion($vacancy,['label'=>'Relevant years','criterion_type'=>'experience_years','operator'=>'gte','expected'=>'3','options'=>[],'decision_mode'=>'minimum','required'=>1,'active'=>1],null);
 $publish->publish($vacancy,'published',null);$sync=new CareersSyncService($service,$client);$result=$sync->run();
 $check($result['published']>=1&&$result['failed']===0,'Sync worker pushes approved revisions over verified TLS');
 $website=new PDO('mysql:host='.getenv('DB_HOST').';dbname=careers_test',getenv('DB_USERNAME'),getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $portal=new Careers\Portal($website,$dir,'https://localhost',$secret);
 $slug=$repo->find('vacancies',$vacancy)['public_slug'];$v=$portal->vacancy($slug);$pdf="%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n";
 $input=['name'=>'TLS Candidate','email'=>'tls@example.test','phone'=>'+254700000001','consent'=>'yes','revision'=>'1','answers'=>[$v['questions'][0]['code']=>'4']];
 $id=$portal->submit($slug,bin2hex(random_bytes(16)),$input,['cv'=>['name'=>'cv.pdf','bytes'=>$pdf],'letter'=>['name'=>'letter.pdf','bytes'=>$pdf]]);
 $result=$sync->run();$check($result['imported']===1&&$result['failed']===0,'Sync worker pulls documents imports and acknowledges over verified TLS');
 $check($sync->run()['imported']===0,'Next sync does not import acknowledged submission again');
 $state=$repo->query('SELECT * FROM recruitment_publication_state WHERE company_id=? AND vacancy_id=?',[$company,$vacancy])->fetch(PDO::FETCH_ASSOC);
 $check((int)$state['published_revision']===1&&$state['last_success_at']!==null,'Successful sync records published revision and timestamp');
 $service->vacancy(['id'=>$vacancy,'reference'=>$repo->find('vacancies',$vacancy)['reference'],'title'=>'TLS integration vacancy','status'=>'closed'],null);
 $result=$sync->run();$check($result['published']===1&&$portal->vacancy($slug)['state']==='closed','Closing in ordinary ERP editor propagates to public website');
}finally{proc_terminate($process);proc_close($process);}
echo "$checks careers TLS sync checks passed\n";
