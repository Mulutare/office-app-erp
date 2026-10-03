<?php
declare(strict_types=1);
if(getenv('APP_ENV')!=='testing'||getenv('DB_DATABASE')!=='office_app_test')exit(1);
require __DIR__.'/../careers/src/bootstrap.php';
// Disposable TLS integration fixture, never part of a deployment package.
[$script,$certificate,$privateKey,$storage]=$argv;
$db=new PDO('mysql:host='.getenv('DB_HOST').';dbname=careers_test',getenv('DB_USERNAME'),getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$portal=new Careers\Portal($db,$storage,'https://localhost',str_repeat('synthetic-careers-only-',3));
$context=stream_context_create(['ssl'=>['local_cert'=>$certificate,'local_pk'=>$privateKey,'verify_peer'=>false]]);
// Server-side client certificates are not required: the application authenticates HMAC.
$server=stream_socket_server('tls://127.0.0.1:443',$errno,$error,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN,$context);
if(!$server)exit(2);
while(true) {
 $socket=@stream_socket_accept($server,2);if(!$socket)continue;
 stream_set_timeout($socket,5);$line=fgets($socket);$parts=explode(' ',trim((string)$line));$headers=[];
 while(($line=fgets($socket))!==false&&trim($line)!==''){[$name,$value]=explode(':',$line,2);$headers[strtolower($name)]=trim($value);}
 $length=(int)($headers['content-length']??0);$body='';
 if($length>100000){fclose($socket);continue;}
 while(strlen($body)<$length){$chunk=fread($socket,$length-strlen($body));if($chunk===false||$chunk==='')break;$body.=$chunk;}
 try{$data=$portal->integration($parts[1]??'',$body,['time'=>$headers['x-careers-time']??'','nonce'=>$headers['x-careers-nonce']??'','signature'=>$headers['x-careers-signature']??'']);$status=200;}
 catch(Throwable $e){$data=['error'=>'denied'];$status=403;}
 $response=json_encode($data,JSON_THROW_ON_ERROR);fwrite($socket,"HTTP/1.1 $status OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($response)."\r\nConnection: close\r\n\r\n".$response);fclose($socket);
}
