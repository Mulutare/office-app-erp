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
 $check(str_contains($response['body'],'Build your future')&&str_contains($response['body'],'There are currently no open positions.'),'Empty listing has branded hero and clear availability message');
 $check(!str_contains($response['body'],'class="pagination"')&&!str_contains($response['body'],'More opportunities'),'Empty listing does not offer pointless pagination');
 $check(str_contains($response['body'],'href="https://passiontechnologiesplc.com/"'),'Public portal links to main company website');
 $check(!str_contains($response['body'],'<script')&&!preg_match('~(?:src|href)="https?://[^\"]+\.(?:js|css)~',$response['body']),'Frontend uses no scripts or external JS/CSS dependencies');
 $headers=implode('',$response['headers']);
 $check(str_contains($headers,'frame-ancestors')&&str_contains($headers,'nosniff'),'Public response carries CSP and restrictive MIME headers');
 $check(str_contains($headers,"default-src 'none'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'"),'Original restrictive CSP remains unchanged');
 $check(!str_contains($response['body'],'erp.')&&!str_contains($response['body'],'CAREERS_INTEGRATION_KEY')&&!str_contains($response['body'],$env['CAREERS_INTEGRATION_KEY']),'Applicant page exposes no ERP endpoint or integration secret');
 $check($request('/careers',['host'=>'evil.example.test'])['status']===503,'Wrong host fails closed');
 $check($request('/careers/integration/pending',['body'=>'{}'])['status']===403,'Unsigned integration HTTP request denied');
 $check($request('/careers/private-document') ['status']===404,'Direct document URL unavailable');
 $website=new PDO($env['CAREERS_DB_DSN'],$env['CAREERS_DB_USER'],$env['CAREERS_DB_PASSWORD']);
 $payload=json_decode($website->query('SELECT payload FROM careers_vacancies LIMIT 1')->fetchColumn(),true);
 // Restore a public fixture so the actual applicant HTML can be tested.
 $v=db()->query("SELECT payload FROM recruitment_publication_revisions WHERE revision=1 ORDER BY id DESC LIMIT 1")->fetchColumn();
 $p=json_decode($v,true);$p['department']='Technology & Operations';$p['location']='Nairobi, Kenya';$p['description']='Build reliable systems. <script>alert(1)</script>';
 $website->prepare("UPDATE careers_vacancies SET state='published',payload=? WHERE reference=?")->execute([json_encode($p,JSON_THROW_ON_ERROR),$p['reference']]);
 $listed=$request('/careers');
 $check(str_contains($listed['body'],'class="vacancy-card"')&&str_contains($listed['body'],'Technology &amp; Operations')&&str_contains($listed['body'],'Nairobi, Kenya')&&str_contains($listed['body'],'View position'),'Vacancy cards show escaped department location and clear action');
 $check(!str_contains($listed['body'],'class="pagination"'),'Single-page results do not show pagination');
 $page=$request('/careers/'.$p['reference']);
 $check($page['status']===200&&str_contains($page['body'],'Submit application')&&str_contains($page['body'],'Application letter'),'Actual vacancy HTTP form renders required documents');
 $check(str_contains($page['body'],'name="_token"')&&str_contains($page['body'],'name="website"'),'Public form includes CSRF and honeypot protection');
 $check($request('/careers/'.$p['reference'],['body'=>['name'=>'Test','_token'=>'wrong']])['status']===419,'Public submission rejects invalid CSRF');
 $check(str_contains($page['body'],'viewport')&&str_contains($page['body'],'for="name"')&&str_contains($page['body'],'aria-describedby'),'Public form has responsive viewport and accessible labels');
 $check(str_contains($page['body'],'&lt;script&gt;alert(1)&lt;/script&gt;')&&!str_contains($page['body'],'<script>'),'Vacancy descriptions remain HTML escaped');
 $check(str_contains($page['body'],'class="upload-field"')&&str_contains($page['body'],'class="skip-link"'),'Form has styled uploads and a keyboard skip link');
 preg_match('/name="_token" value="([a-f0-9]+)"/',$page['body'],$token);
 preg_match('/Set-Cookie: (careers_session=[^;]+)/i',implode('',$page['headers']),$cookie);
 sleep(3); // Exercise the real low-friction elapsed-form check.
 $invalid=$request('/careers/'.$p['reference'],['cookie'=>$cookie[1],'body'=>['_token'=>$token[1],'revision'=>(string)$p['revision'],'name'=>'HTTP Candidate','email'=>'bad','phone'=>'123','consent'=>'yes']]);
 $check($invalid['status']===422&&str_contains($invalid['body'],'id="error-email"')&&str_contains($invalid['body'],'aria-invalid="true"'),'Validation retains field-specific errors and accessible invalid state');
 $pdf=sys_get_temp_dir().'/careers-http-upload.pdf';file_put_contents($pdf,"%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n");
 $form=['_token'=>$token[1],'revision'=>(string)$p['revision'],'name'=>'HTTP Candidate','email'=>'http@example.test','phone'=>'+254700000002','consent'=>'yes','answers['.$p['questions'][0]['code'].']'=>'4','cv'=>new CURLFile($pdf,'application/pdf','cv.pdf'),'letter'=>new CURLFile($pdf,'application/pdf','letter.pdf')];
 $submitted=$request('/careers/'.$p['reference'],['cookie'=>$cookie[1],'body'=>$form]);
 $check($submitted['status']===303,'Real multipart applicant submission succeeds with CSRF and two uploads');
 $confirmation=$request('/careers/'.$p['reference'].'?submitted=1',['cookie'=>$cookie[1]]);
 $check($confirmation['status']===200&&str_contains($confirmation['body'],'Application submitted successfully'),'HTTP confirmation displays success after redirect');
 $check(preg_match('/<strong>[a-f0-9]{32}<\/strong>/',$confirmation['body'])===1&&str_contains($confirmation['body'],'Back to Careers')&&!str_contains($confirmation['body'],'application_id'),'Success shows opaque reference and return link without ERP identity');
 $count=(int)$website->query('SELECT COUNT(*) FROM careers_submissions')->fetchColumn();
 $check($request('/careers/'.$p['reference'],['cookie'=>$cookie[1],'body'=>$form])['status']===303&&(int)$website->query('SELECT COUNT(*) FROM careers_submissions')->fetchColumn()===$count,'Real multipart retry creates no second submission');
 // These HTTP-only fixtures are not queued for the separate TLS worker test.
 $website->exec('UPDATE careers_submissions SET acknowledged=1');
}finally{proc_terminate($process);proc_close($process);}
echo "$checks careers HTTP checks passed\n";
