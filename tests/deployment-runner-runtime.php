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
    foreach(['preflight','staged-migration-audit','finalize-release','database-backup','application-backup','migrate-next','sync-reference-data','release-health','cutover','rollback-application'] as $action){
        $r=$run($helpers.'$action='.var_export($action,true).';'.$guard.'emitJson(["limit"=>ini_get("max_execution_time")]);',['-d','max_execution_time=30']);
        $check(($r['json']['limit']??null)==='0','Unlimited runtime verified for '.$action);
    }
    foreach(['upload-chunk','cleanup'] as $action){
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
    $large=fopen($live.'/storage/private/large-evidence','w');ftruncate($large,128*1024*1024);fclose($large);
    foreach(['valid','bad-size','bad-sha','bad-root','database-config','local-config','runtime-data'] as $index=>$case){
        $id='a011e6e-202610030919'.str_pad((string)$index,2,'0',STR_PAD_LEFT);$dir=$stage.'/'.$id;mkdir($dir.'/.upload',0700,true);
        $tar=$temp.'/archive-'.$index.'.tar';$phar=new PharData($tar);
        $phar['office_app/app/bootstrap.php']='<?php';$phar['office_app/vendor/autoload.php']='<?php';$phar['office_app/config/placeholder']='safe';
        foreach(['cache','logs','private','uploads']as$name)$phar->addEmptyDir('office_app/storage/'.$name);
        if($case==='runtime-data')$phar['office_app/storage/private/packaged']='forbidden';
        if($case==='bad-root')$phar['outside/evil']='untrusted';
        if($case==='database-config')$phar['office_app/config/database.php']='untrusted';
        if($case==='local-config')$phar['office_app/config/app.local.php']='untrusted';
        $phar->compress(Phar::GZ);unset($phar);$part=$dir.'/.upload/package.tar.gz.part';copy($tar.'.gz',$part);
        $state=['release_id'=>$id,'state'=>'uploading','size'=>filesize($part)+($case==='bad-size'?1:0),'sha256'=>$case==='bad-sha'?str_repeat('0',64):hash_file('sha256',$part)];
        file_put_contents($dir.'/.deployment.json',json_encode($state));
        $code=$common.'$action="finalize-release";$request=["release_id"=>'.var_export($id,true).'];$result=[];try{if(false){}'.$branch('finalize-release','database-backup').'emitJson($result);}catch(Throwable $e){emitJson(["error"=>"rejected","detail"=>$e->getMessage()]);}';
        $r=$run($code);
        if($case==='valid'){if(isset($r['json']['error']))echo 'Fixture diagnostic: '.$r['json']['detail'].' '.json_encode(glob($dir.'/office_app/storage/*')).PHP_EOL;
            $check(($r['json']['sha256']??null)===$state['sha256']&&json_decode(file_get_contents($dir.'/.deployment.json'),true)['state']==='staged','Verified archive finalizes to staged with matching hash');
            $check(file_get_contents($dir.'/office_app/config/database.php')==='server-only-db'&&file_get_contents($dir.'/office_app/config/app.local.php')==='server-only-local'&&count(glob($dir.'/office_app/storage/*'))===4&&!file_exists($dir.'/office_app/storage/private/fixture')&&!file_exists($dir.'/office_app/storage/private/large-evidence'),'Protected config copied after verification; large live storage remains absent from staged skeleton');
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
    // Full handoff action tests: real renames on temporary roots, injected syscall failures only.
    $makeFixture=static function(string $name)use($temp):array {
        $base=$temp.'/handoff-'.$name;mkdir($base,0700);$live=$base.'/live';$stage=$base.'/staging';$id='a48a157-20261003151957';$target=$stage.'/'.$id.'/office_app';
        foreach([$live,$target]as$root){
            foreach(['app','bin','config','database','deployment','docs','public','resources','routes','vendor']as$dir)mkdir($root.'/'.$dir,0700,true);
            foreach(['vendor/autoload.php','config/database.php','config/app.local.php','index.php','app/code.php']as$file)file_put_contents($root.'/'.$file,$root===$live?'old-code':'new-code');
            foreach(['cache','logs','private','uploads']as$dir)mkdir($root.'/storage/'.$dir,0700,true);
        }
        mkdir($live.'/storage/backups',0700);file_put_contents($live.'/storage/backups/database.sql.gz','database-backup');
        file_put_contents($live.'/storage/private/attachment','private-attachment');file_put_contents($live.'/storage/private/raw.eml','raw-mail');
        file_put_contents($live.'/storage/logs/log','log');file_put_contents($live.'/storage/uploads/upload','upload');file_put_contents($live.'/storage/cache/cache','cache');
        chmod($live.'/storage/private/attachment',0600);
        $state=['release_id'=>$id,'state'=>'ready','commit'=>str_repeat('a',40),'database_backup'=>'database.sql.gz'];file_put_contents($stage.'/'.$id.'/.deployment.json',json_encode($state));
        file_put_contents($base.'/lock',$id);
        return compact('base','live','stage','id','target');
    };
    $execute=static function(array $f,string $action,array $fail=[],string $extra='')use($run,$helpers,$branch,$slice):array {
        $setup='namespace HandoffFixture;use RuntimeException;use Throwable;use FilesystemIterator;use RecursiveIteratorIterator;use RecursiveDirectoryIterator;';
        $setup.='const LIVE_ROOT='.var_export($f['live'],true).';const STAGING_ROOT='.var_export($f['stage'],true).';const DEPLOY_LOCK='.var_export($f['base'].'/lock',true).';';
        $setup.='$moves=0;$metadataWrites=0;$metadataFailures=[];$fail='.var_export($fail,true).';function gmdate($f){return "20261003170000";}';
        $setup.='function rename($from,$to){global $moves,$fail,$metadataWrites,$metadataFailures;if(is_dir($from)){++$moves;if(in_array($moves,$fail,true))return false;}elseif(basename($to)===".deployment.json"){++$metadataWrites;if(in_array($metadataWrites,$metadataFailures,true))return false;}return \\rename($from,$to);}';
        $setup.='function copy($from,$to){if(str_starts_with($from,LIVE_ROOT."/storage/"))throw new RuntimeException("Runtime copy forbidden");return \\copy($from,$to);}';
        $body=match($action){'application-backup'=>$branch('application-backup','migrate-next'),'cutover'=>$branch('cutover','rollback-application'),'rollback-application'=>$branch('rollback-application','cleanup'),'cleanup'=>$slice(" elseif(\$action==='cleanup')",' if(!$readOnly)')};
        return $run($setup.$helpers.$extra.'$action='.var_export($action,true).';$request=["release_id"=>'.var_export($f['id'],true).'];$result=[];try{if(false){}'.$body.'emitJson(["ok"=>true,"result"=>$result,"moves"=>$moves]);}catch(Throwable $e){emitJson(["ok"=>false,"error"=>$e->getMessage(),"moves"=>$moves]);}');
    };
    $snapshot=static function(string $root):array {
        $data=[];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS))as$i)if($i->isFile())$data[substr($i->getPathname(),strlen($root))]=hash_file('sha256',$i->getPathname());ksort($data);return$data;
    };
    $f=$makeFixture('success');$before=$snapshot($f['live'].'/storage');$inode=fileinode($f['live'].'/storage');
    $r=$execute($f,'cutover');$previous=$f['base'].'/office_app_failed_20261003170000_'.$f['id'];
    $check(($r['json']['ok']??false)&&$r['json']['moves']===3&&file_get_contents($f['live'].'/app/code.php')==='new-code','Cutover activates new code with exactly three directory renames');
    clearstatcache();
    $check($snapshot($f['live'].'/storage')===$before&&fileinode($f['live'].'/storage')===$inode&&(fileperms($f['live'].'/storage/private/attachment')&0777)===0600,'Cutover preserves runtime bytes inode permissions and database backup');
    $check(file_get_contents($previous.'/app/code.php')==='old-code'&&!file_exists($previous.'/storage')&&!file_exists($f['target']),'Previous code retained without a duplicate runtime tree');
    file_put_contents($f['live'].'/storage/private/after-cutover','new-runtime-evidence');$after=$snapshot($f['live'].'/storage');
    $r=$execute($f,'rollback-application');$bad=$f['base'].'/office_app_failed_health_20261003170000_'.$f['id'];clearstatcache();
    $check(($r['json']['ok']??false)&&$r['json']['moves']===3&&file_get_contents($f['live'].'/app/code.php')==='old-code'&&$snapshot($f['live'].'/storage')===$after&&fileinode($f['live'].'/storage')===$inode,'Rollback restores old code with the same storage including post-cutover evidence');
    $check(file_get_contents($bad.'/app/code.php')==='new-code'&&!file_exists($bad.'/storage'),'Failed-health application isolated without runtime duplication');

    $f=$makeFixture('backup');$statePath=$f['stage'].'/'.$f['id'].'/.deployment.json';$state=json_decode(file_get_contents($statePath),true);
    $r=$execute($f,'application-backup');$check(!($r['json']['ok']??true),'Application backup still requires verified database backup');
    $state['state']='database-backed-up';file_put_contents($statePath,json_encode($state));$r=$execute($f,'application-backup');$backup=$f['base'].'/'.($r['json']['result']['backup']['name']??'absent');
    $check(($r['json']['ok']??false)&&json_decode(file_get_contents($statePath),true)['state']==='backed-up','Independent code/config backup succeeds and advances its gate');
    $check(is_file($backup.'/app/code.php')&&is_file($backup.'/vendor/autoload.php')&&file_get_contents($backup.'/config/database.php')==='old-code'&&file_get_contents($backup.'/config/app.local.php')==='old-code'&&is_file($backup.'/index.php')&&!file_exists($backup.'/storage')&&is_file($f['live'].'/storage/backups/database.sql.gz'),'Application backup includes code config vendor root files but excludes all runtime evidence');
    $all=true;foreach(['bin','database','deployment','docs','public','resources','routes']as$dir)$all=$all&&is_dir($backup.'/'.$dir);$check($all,'Code backup retains every application directory');

    foreach(['cutover','rollback-application']as$action)foreach([1,2,3]as$step){
        $f=$makeFixture($action.'-'.$step);if($action==='rollback-application')$execute($f,'cutover');
        $originalCode=file_get_contents($f['live'].'/app/code.php');$before=$snapshot($f['live'].'/storage');$inode=fileinode($f['live'].'/storage');
        $r=$execute($f,$action,[$step]);clearstatcache();
        $check(!($r['json']['ok']??true)&&file_get_contents($f['live'].'/app/code.php')===$originalCode&&$snapshot($f['live'].'/storage')===$before&&fileinode($f['live'].'/storage')===$inode,$action.' rename failure '.$step.' compensates without losing runtime storage');
        $check(json_decode(file_get_contents($f['stage'].'/'.$f['id'].'/.deployment.json'),true)['state']===($action==='cutover'?'ready':'cutover'),$action.' compensated state remains retryable after failure '.$step);
    }
    foreach(['cutover','rollback-application']as$action){
        $f=$makeFixture($action.'-metadata-failure');if($action==='rollback-application')$execute($f,'cutover');
        $code=file_get_contents($f['live'].'/app/code.php');$before=$snapshot($f['live'].'/storage');
        $r=$execute($f,$action,[],'$metadataFailures=[2];');
        $check(!($r['json']['ok']??true)&&($r['json']['moves']??0)===6&&file_get_contents($f['live'].'/app/code.php')===$code&&$snapshot($f['live'].'/storage')===$before,$action.' metadata failure after activation reverses all three moves');
        $state=json_decode(file_get_contents($f['stage'].'/'.$f['id'].'/.deployment.json'),true);
        $check($state['state']===($action==='cutover'?'ready':'cutover'),$action.' atomic metadata update preserves and restores valid state');
        $f=$makeFixture($action.'-recovery-failure');if($action==='rollback-application')$execute($f,'cutover');
        $r=$execute($f,$action,[3,4]);
        $state=json_decode(file_get_contents($f['stage'].'/'.$f['id'].'/.deployment.json'),true);$target=$state['handoff']['target'];
        $check(!($r['json']['ok']??true)&&$state['state']===($action==='cutover'?'cutover-pending':'rollback-pending')&&is_file($target.'/storage/private/attachment'),$action.' failed compensation preserves storage and durable recovery intent');
        $r=$execute($f,'cleanup');$check(!($r['json']['ok']??true)&&is_file($f['base'].'/lock'),$action.' unresolved handoff prevents cleanup from removing deployment lock');
    }
    foreach(['root-link','nested-link','packaged-data','collision','dangling-collision','target-link','rollback-collision','invalid-previous','cross-release-previous']as$case){
        $f=$makeFixture('security-'.$case);$root=$f['live'];$action='cutover';
        if($case==='root-link'){rename($root.'/storage',$f['base'].'/elsewhere');symlink($f['base'].'/elsewhere',$root.'/storage');}
        if($case==='nested-link')symlink($f['base'],$root.'/storage/private/link');
        if($case==='packaged-data')file_put_contents($f['target'].'/storage/private/unexpected','business-data');
        if($case==='collision')mkdir($f['base'].'/office_app_failed_20261003170000_'.$f['id']);
        if($case==='dangling-collision')symlink($f['base'].'/nonexistent',$f['base'].'/office_app_failed_20261003170000_'.$f['id']);
        if($case==='target-link'){rename($f['target'],$f['base'].'/linked-stage');symlink($f['base'].'/linked-stage',$f['target']);}
        if($case==='rollback-collision'){$execute($f,'cutover');$action='rollback-application';mkdir($f['base'].'/office_app_failed_health_20261003170000_'.$f['id']);}
        if(in_array($case,['invalid-previous','cross-release-previous'],true)){
            $execute($f,'cutover');$action='rollback-application';$p=$f['stage'].'/'.$f['id'].'/.deployment.json';$state=json_decode(file_get_contents($p),true);$state['cutover_previous']=$case==='invalid-previous'?'/tmp/../unexpected':$f['base'].'/office_app_failed_20261003170000_bbbbbbb-20261003151957';file_put_contents($p,json_encode($state));
        }
        $code=file_get_contents($root.'/app/code.php');$r=$execute($f,$action);
        $check(!($r['json']['ok']??true)&&($r['json']['moves']??-1)===0&&file_get_contents($root.'/app/code.php')===$code,'Unsafe handoff rejected before live rename: '.$case);
    }
    $f=$makeFixture('invalid-id');$f['id']='../../bad';$r=$execute($f,'cutover');$check(($r['json']['error']??null)==='invalid_release_id','Traversal release ID rejected before handoff');
    $f=$makeFixture('pending');$p=$f['stage'].'/'.$f['id'].'/.deployment.json';$state=json_decode(file_get_contents($p),true);$state['state']='cutover-pending';file_put_contents($p,json_encode($state));$r=$execute($f,'cutover');
    $check(!($r['json']['ok']??true)&&$r['json']['moves']===0,'Interrupted pending handoff cannot be automatically retried');
    foreach(['cutover','cleanup']as$action){
        $f=$makeFixture('busy-'.$action);$r=$execute($f,$action,[],'$busy=fopen(STAGING_ROOT."/.handoff.lock","c");flock($busy,LOCK_EX);');
        $check(!($r['json']['ok']??true)&&$r['json']['moves']===0&&is_file($f['base'].'/lock'),'Active handoff prevents concurrent '.$action.' and preserves deployment lock');
    }
}catch(Throwable $e){$check(false,$e->getMessage());}
finally {
    $remove=static function(string $path)use(&$remove):void{if(is_dir($path)&&!is_link($path)){foreach(new FilesystemIterator($path)as$item)$remove($item->getPathname());rmdir($path);}else unlink($path);};
    $remove($temp);
}
echo ($passed+$failed).' runner runtime checks, '.$failed." failures\n";
exit($failed?1:0);
