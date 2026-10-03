<?php
declare(strict_types=1);
require __DIR__.'/../app/helpers/bootstrap.php';
if(getenv('APP_ENV')!=='testing'||getenv('DB_DATABASE')!=='office_app_test')throw new RuntimeException('Disposable database required.');
$checks=0;$check=function($ok,$label)use(&$checks){if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;echo "PASS $label\n";};
$env=getenv();$env['CAREERS_BASE_URL']='https://careers.example.test';$env['CAREERS_DB_DSN']='mysql:host='.getenv('DB_HOST').';dbname=careers_test';$env['CAREERS_DB_USER']=getenv('DB_USERNAME');$env['CAREERS_DB_PASSWORD']=getenv('DB_PASSWORD');$env['CAREERS_INTEGRATION_KEY']=str_repeat('synthetic-careers-only-',3);$env['CAREERS_STORAGE']=sys_get_temp_dir().'/careers-http-docs';
if(!is_dir($env['CAREERS_STORAGE']))mkdir($env['CAREERS_STORAGE'],0700);
$log=sys_get_temp_dir().'/careers-http.log';
$process=proc_open([PHP_BINARY,'-S','127.0.0.1:8097',__DIR__.'/careers-http-router.php'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__),$env);
$request=static function(string $path,array $options=[]):array{
 $curl=curl_init('http://127.0.0.1:8097'.$path);$headers=[];
 curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>['Host: '.($options['host']??'careers.example.test')],CURLOPT_HEADERFUNCTION=>static function($c,$line)use(&$headers){$headers[]=$line;return strlen($line);}]);
 if(isset($options['body']))curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$options['body']]);
 if(isset($options['cookie']))curl_setopt($curl,CURLOPT_COOKIE,$options['cookie']);
 $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);return compact('status','body','headers');
};
try {
 for($i=0;$i<30;$i++){usleep(100000);$response=$request('/careers');if($response['status'])break;}
 $check($response['status']===200,'Public Careers HTTP listing renders');
 $headers=implode('',$response['headers']);
 $check(str_contains($headers,'frame-ancestors')&&str_contains($headers,'nosniff'),'Public response carries CSP and restrictive MIME headers');
 $check(!str_contains($response['body'],'erp.')&&!str_contains($response['body'],'CAREERS_INTEGRATION_KEY')&&!str_contains($response['body'],$env['CAREERS_INTEGRATION_KEY']),'Applicant page exposes no ERP endpoint or integration secret');
 $check($request('/careers',['host'=>'evil.example.test'])['status']===503,'Wrong host fails closed');
 $check($request('/careers/integration/pending',['body'=>'{}'])['status']===403,'Unsigned integration HTTP request denied');
 $check($request('/careers/private-document') ['status']===404,'Direct document URL unavailable');
 $website=new PDO($env['CAREERS_DB_DSN'],$env['CAREERS_DB_USER'],$env['CAREERS_DB_PASSWORD']);
 $payload=json_decode($website->query('SELECT payload FROM careers_vacancies LIMIT 1')->fetchColumn(),true);
 // Restore a public fixture so the actual applicant HTML can be tested.
 $v=db()->query("SELECT payload FROM recruitment_publication_revisions WHERE revision=1 ORDER BY id DESC LIMIT 1")->fetchColumn();
 $p=json_decode($v,true);$website->prepare("UPDATE careers_vacancies SET state='published',payload=? WHERE reference=?")->execute([$v,$p['reference']]);
 $page=$request('/careers/'.$p['reference']);
 $check($page['status']===200&&str_contains($page['body'],'Submit application')&&str_contains($page['body'],'Application letter'),'Actual vacancy HTTP form renders required documents');
 $check(str_contains($page['body'],'name="_token"')&&str_contains($page['body'],'name="website"'),'Public form includes CSRF and honeypot protection');
 $check($request('/careers/'.$p['reference'],['body'=>['name'=>'Test','_token'=>'wrong']])['status']===419,'Public submission rejects invalid CSRF');
 $check(str_contains($page['body'],'viewport')&&str_contains($page['body'],'for="name"')&&str_contains($page['body'],'aria-describedby'),'Public form has responsive viewport and accessible labels');
 preg_match('/name="_token" value="([a-f0-9]+)"/',$page['body'],$token);
 preg_match('/Set-Cookie: (careers_session=[^;]+)/i',implode('',$page['headers']),$cookie);
 sleep(3); // Exercise the real low-friction elapsed-form check.
 $pdf=sys_get_temp_dir().'/careers-http-upload.pdf';file_put_contents($pdf,"%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n");
 $form=['_token'=>$token[1],'revision'=>(string)$p['revision'],'name'=>'HTTP Candidate','email'=>'http@example.test','phone'=>'+254700000002','consent'=>'yes','answers['.$p['questions'][0]['code'].']'=>'4','cv'=>new CURLFile($pdf,'application/pdf','cv.pdf'),'letter'=>new CURLFile($pdf,'application/pdf','letter.pdf')];
 $submitted=$request('/careers/'.$p['reference'],['cookie'=>$cookie[1],'body'=>$form]);
 $check($submitted['status']===303,'Real multipart applicant submission succeeds with CSRF and two uploads');
 $confirmation=$request('/careers/'.$p['reference'].'?submitted=1',['cookie'=>$cookie[1]]);
 $check($confirmation['status']===200&&str_contains($confirmation['body'],'Application submitted successfully'),'HTTP confirmation displays success after redirect');
 $count=(int)$website->query('SELECT COUNT(*) FROM careers_submissions')->fetchColumn();
 $check($request('/careers/'.$p['reference'],['cookie'=>$cookie[1],'body'=>$form])['status']===303&&(int)$website->query('SELECT COUNT(*) FROM careers_submissions')->fetchColumn()===$count,'Real multipart retry creates no second submission');
 // These HTTP-only fixtures are not queued for the separate TLS worker test.
 $website->exec('UPDATE careers_submissions SET acknowledged=1');
}finally{proc_terminate($process);proc_close($process);}
echo "$checks careers HTTP checks passed\n";
