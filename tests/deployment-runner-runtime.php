<?php
declare(strict_types=1);

// Execute the real runner's isolated blocks in subprocesses. Never load its
// authentication/bootstrap or production constants, and never connect to a DB.
$source=str_replace("\r\n","\n",(string)file_get_contents(__DIR__.'/../deployment/production-runner.php'));
$slice=static function(string $start,string $end)use($source):string {
    $a=strpos($source,$start);$b=strpos($source,$end,$a===false?0:$a);
    if($a===false||$b===false)throw new RuntimeException('Runner test boundary missing');
    return substr($source,$a,$b-$a);
};
$helpers=$slice('$responseSent=false;','$headerRoot=');
$fatalCode=$slice('function fatalErrorCode(',"// Keep runtime diagnostics");
$shutdown=$slice('$fatalResponseReserve=', 'if(!$readOnly)');
$guard=$slice('$runtimeLimitBefore=',"try{\n");
$branch=static fn(string $action,string $next):string=>$slice(" elseif(\$action==='$action')", " elseif(\$action==='$next')");
$catch=substr($source,strpos($source,'}catch(Throwable$e)'));
$passed=0;$failed=0;
$check=static function(bool $ok,string $label)use(&$passed,&$failed):void {echo ($ok?'PASS ':'FAIL ').$label."\n";$ok?$passed++:$failed++;};
$temp=sys_get_temp_dir().'/deployment-runtime-'.bin2hex(random_bytes(8));mkdir($temp,0700);
$run=static function(string $code,array $flags=[])use($temp):array {
    $path=$temp.'/child-'.bin2hex(random_bytes(6)).'.php';file_put_contents($path,"<?php\n".$code);
    $proc=proc_open(array_merge([PHP_BINARY],$flags,[$path]),[1=>['pipe','w'],2=>['file',$temp.'/stderr.log','a']],$pipes);
    if(!is_resource($proc))throw new RuntimeException('Cannot start isolated PHP');
    $output=stream_get_contents($pipes[1]);fclose($pipes[1]);$exit=proc_close($proc);
    return ['json'=>json_decode($output,true),'output'=>$output,'exit'=>$exit];
};
try {
    foreach(['preflight','staged-migration-audit','finalize-release','database-backup','application-backup','migrate-next','sync-reference-data','release-health'] as $action){
        $r=$run($helpers.'$action='.var_export($action,true).';'.$guard.'emitJson(["limit"=>ini_get("max_execution_time")]);',['-d','max_execution_time=30']);
        $check(($r['json']['limit']??null)==='0','Unlimited runtime verified for '.$action);
    }
    foreach(['cutover','rollback-application','upload-chunk','cleanup'] as $action){
        $r=$run($helpers.'$action='.var_export($action,true).';'.$guard.'emitJson(["limit"=>ini_get("max_execution_time")]);',['-d','max_execution_time=30']);
        $check(($r['json']['limit']??null)==='30','Bounded action retains runtime limit: '.$action);
    }
    $r=$run($helpers.'$action="finalize-release";'.$guard.'echo "UNSAFE_WORK_REACHED";',['-d','max_execution_time=30','-d','disable_functions=set_time_limit']);
    $check(($r['json']['error']??null)==='deployment_runtime_timeout_limit'&&!str_contains($r['output'],'UNSAFE_WORK'),'Unavailable runtime extension fails closed before action work');
    // A host may report success without retaining the setting. Exercise that case.
    $r=$run('namespace RuntimeFixture; function set_time_limit($n){return true;} function ini_get($n){return "30";}'.$helpers.'$action="finalize-release";'.$guard.'echo "UNSAFE_WORK_REACHED";');
    $check(($r['json']['error']??null)==='deployment_runtime_timeout_limit','Unretained runtime extension fails closed');
    $fatalSetup=$fatalCode.$helpers.$slice("ini_set('display_errors'", "header('Content-Type").'$action="finalize-release";$request=["release_id"=>"a011e6e-20261003091905"];'.$shutdown;
    $r=$run($fatalSetup.'trigger_error("SECRET_CONFIG_ARCHIVE_SENTINEL",E_USER_ERROR);',['-d','display_errors=1']);
    $check(($r['json']['error']??null)==='deployment_fatal_error'&&($r['json']['release_id']??null)==='a011e6e-20261003091905'&&!str_contains($r['output'],'SECRET_'),'PHP fatal returns clean JSON with validated release context and no diagnostic contents');
    $r=$run($fatalSetup.'$bytes=str_repeat("x",32*1024*1024);',['-d','memory_limit=16M']);
    $check(($r['json']['error']??null)==='php_memory_exhausted','Memory exhaustion reserve permits structured fatal JSON');
    $r=$run($fatalSetup.'while(true){}',['-d','max_execution_time=1']);
    $check(($r['json']['error']??null)==='php_execution_timeout','PHP execution timeout returns structured JSON when shutdown can run');
    $r=$run($fatalSetup.'emitJson(["ok"=>true]);trigger_error("secret",E_USER_ERROR);');
    $check($r['json']===['ok'=>true],'Shutdown never appends a second response');
    $r=$run($fatalSetup.'try{throw new RuntimeException("SECRET_CONFIG_ARCHIVE_SENTINEL");'.$catch);
    $check(($r['json']['error']??null)==='deployment_action_failed'&&!str_contains($r['output'],'SECRET_'),'Caught exceptions do not expose arbitrary config or archive diagnostics');

    // Actual finalize branch, real Phar archives, exclusively temporary paths.
    $live=$temp.'/live';$stage=$temp.'/staging';mkdir($live.'/config',0700,true);mkdir($live.'/storage/private',0700,true);mkdir($stage,0700);
    file_put_contents($live.'/config/database.php','server-only-db');file_put_contents($live.'/config/app.local.php','server-only-local');file_put_contents($live.'/storage/private/fixture','private-storage');
    $common='const LIVE_ROOT='.var_export($live,true).';const STAGING_ROOT='.var_export($stage,true).';const DEPLOY_LOCK='.var_export($temp.'/lock',true).';'.$helpers;
    foreach(['valid','bad-size','bad-sha','bad-root','database-config','local-config'] as $index=>$case){
        $id='a011e6e-202610030919'.str_pad((string)$index,2,'0',STR_PAD_LEFT);$dir=$stage.'/'.$id;mkdir($dir.'/.upload',0700,true);
        $tar=$temp.'/archive-'.$index.'.tar';$phar=new PharData($tar);
        $phar['office_app/vendor/autoload.php']='<?php';$phar['office_app/config/placeholder']='safe';$phar['office_app/storage/packaged']='remove-me';
        if($case==='bad-root')$phar['outside/evil']='untrusted';
        if($case==='database-config')$phar['office_app/config/database.php']='untrusted';
        if($case==='local-config')$phar['office_app/config/app.local.php']='untrusted';
        $phar->compress(Phar::GZ);unset($phar);$part=$dir.'/.upload/package.tar.gz.part';copy($tar.'.gz',$part);
        $state=['release_id'=>$id,'state'=>'uploading','size'=>filesize($part)+($case==='bad-size'?1:0),'sha256'=>$case==='bad-sha'?str_repeat('0',64):hash_file('sha256',$part)];
        file_put_contents($dir.'/.deployment.json',json_encode($state));
        $code=$common.'$action="finalize-release";$request=["release_id"=>'.var_export($id,true).'];$result=[];try{if(false){}'.$branch('finalize-release','database-backup').'emitJson($result);}catch(Throwable $e){emitJson(["error"=>"rejected"]);}';
        $r=$run($code);
        if($case==='valid'){
            $check(($r['json']['sha256']??null)===$state['sha256']&&json_decode(file_get_contents($dir.'/.deployment.json'),true)['state']==='staged','Verified archive finalizes to staged with matching hash');
            $check(file_get_contents($dir.'/office_app/config/database.php')==='server-only-db'&&file_get_contents($dir.'/office_app/config/app.local.php')==='server-only-local'&&file_get_contents($dir.'/office_app/storage/private/fixture')==='private-storage'&&!file_exists($dir.'/office_app/storage/packaged'),'Protected config and storage come only from server after verification');
        }else{
            $check(($r['json']['error']??null)==='rejected'&&!is_dir($dir.'/office_app'),'Finalize rejects '.$case.' before extraction or protected config copy');
        }
    }
    $id='a011e6e-20261003091900';
    foreach(['uploading','staged','database-backed-up'] as $state){
        file_put_contents($stage.'/'.$id.'/.deployment.json',json_encode(['release_id'=>$id,'state'=>$state]));
        $r=$run($common.'$action="migrate-next";$request=["release_id"=>"'.$id.'","expected_version"=>"111"];try{if(false){}'.$branch('migrate-next','sync-reference-data').'}catch(Throwable $e){emitJson(["error"=>$e->getMessage()]);}');
        $check(($r['json']['error']??null)==='Verified backups or active migration sequence required.','Migration denied before verified backups: '.$state);
    }
    file_put_contents($temp.'/lock',$id);
    $r=$run($common.'$action="cleanup";$request=["release_id"=>"'.$id.'"];$result=[];if(false){}'.$slice(" elseif(\$action==='cleanup')",' if(!$readOnly)').'emitJson($result);');
    $check(($r['json']['lock_removed']??false)&&is_dir($stage.'/'.$id)&&is_file($live.'/storage/private/fixture'),'Cleanup removes matching lock while retaining staging and live storage');
    $begin=$branch('begin-release','upload-chunk');
    $r=$run($common.'$action="begin-release";$request=["release_id"=>"'.$id.'","commit"=>str_repeat("a",40),"size"=>1024,"sha256"=>str_repeat("a",64)];try{if(false){}'.$begin.'}catch(Throwable $e){emitJson(["error"=>$e->getMessage()]);}');
    $check(($r['json']['error']??null)==='Cannot create unique release staging directory.','Existing release ID cannot reuse retained staging bytes');
}catch(Throwable $e){$check(false,$e->getMessage());}
finally {
    $remove=static function(string $path)use(&$remove):void{if(is_dir($path)&&!is_link($path)){foreach(new FilesystemIterator($path)as$item)$remove($item->getPathname());rmdir($path);}else unlink($path);};
    $remove($temp);
}
echo ($passed+$failed).' runner runtime checks, '.$failed." failures\n";
exit($failed?1:0);
