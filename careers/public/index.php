<?php
declare(strict_types=1);
use App\Services\Recruitment\CareersContract as Contract;
ini_set('display_errors','0');
require dirname(__DIR__).'/src/bootstrap.php';
header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('Referrer-Policy: no-referrer'); header('Cache-Control: no-store');
header('Strict-Transport-Security: max-age=31536000');
$e=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$errors=[]; $input=[]; $v=null; $listing=[]; $success=null; $message=null;
try {
    $origin=Contract::origin((string)getenv('CAREERS_BASE_URL'));
    // A reverse proxy must set HTTPS=on server-side; never trust a client forwarding header.
    if(($_SERVER['HTTPS']??'')!=='on' || strtolower($_SERVER['HTTP_HOST']??'')!==parse_url($origin,PHP_URL_HOST)) { http_response_code(400); throw new RuntimeException('Origin mismatch.'); }
    $db=new PDO((string)getenv('CAREERS_DB_DSN'),(string)getenv('CAREERS_DB_USER'),(string)getenv('CAREERS_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $portal=new Careers\Portal($db,(string)getenv('CAREERS_STORAGE'),$origin,(string)getenv('CAREERS_INTEGRATION_KEY'));
    $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH); $method=$_SERVER['REQUEST_METHOD'];
    if(str_starts_with($path,'/careers/integration/')) {
        header('Content-Type: application/json');
        try {
            if($method!=='POST'||isset($_SERVER['HTTP_ORIGIN'])||($_SERVER['CONTENT_TYPE']??'')!=='application/json'||(int)($_SERVER['CONTENT_LENGTH']??0)>100000) throw new InvalidArgumentException('Denied.');
            $body=file_get_contents('php://input',false,null,0,100001);
            if(!is_string($body)||strlen($body)>100000) throw new InvalidArgumentException('Denied.');
            $result=$portal->integration($path,$body,['time'=>$_SERVER['HTTP_X_CAREERS_TIME']??'','nonce'=>$_SERVER['HTTP_X_CAREERS_NONCE']??'','signature'=>$_SERVER['HTTP_X_CAREERS_SIGNATURE']??'']);
            echo json_encode($result,JSON_THROW_ON_ERROR);
        } catch(Throwable $err) { http_response_code(403); error_log('careers integration denied'); echo '{"error":"Integration request denied"}'; }
        exit;
    }
    session_name('careers_session'); session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Lax','path'=>'/careers']); session_start();
    $_SESSION['csrf']??=bin2hex(random_bytes(32));
    if(!in_array($method,['GET','POST'],true)) { http_response_code(405); throw new InvalidArgumentException('Method unavailable.'); }
    if($path==='/careers'||$path==='/careers/') $listing=$portal->vacancies(max(1,(int)($_GET['page']??1)));
    elseif(preg_match('~^/careers/([a-z0-9-]{1,190})$~D',$path,$match)) {
        $reference=$match[1]; $v=$portal->vacancy($reference);
        $_SESSION['forms'][$reference]??=['id'=>bin2hex(random_bytes(16)),'started'=>time()];
        if(count($_SESSION['forms'])>30) array_shift($_SESSION['forms']);
        $form=$_SESSION['forms'][$reference];
        if($method==='POST') {
            $portal->limit($_SERVER['REMOTE_ADDR']??'unknown');
            if(!is_string($_POST['_token']??null)||!hash_equals($_SESSION['csrf'],$_POST['_token'])||($_SERVER['HTTP_ORIGIN']??$origin)!==$origin) { http_response_code(419); throw new InvalidArgumentException('Your form session expired. Reload and try again.'); }
            if(!empty($_POST['website'])||time()-$form['started']<3) throw new InvalidArgumentException('Please review the form and try again.');
            // Optional server-owned CAPTCHA callback. It must return true; errors fail closed.
            $botCheck=getenv('CAREERS_BOT_CHECK_FILE');
            if($botCheck) { $check=require $botCheck; if(!is_callable($check)||$check($_POST,$_SERVER['REMOTE_ADDR']??'')!==true) throw new InvalidArgumentException('Verification failed. Please try again.'); }
            $input=$_POST; $files=[];
            if(count($_FILES)>2) $errors['documents']='Upload exactly two documents.';
            foreach(['cv','letter'] as $role) {
                $f=$_FILES[$role]??null;
                if(!$f||!is_int($f['error'])||$f['error']!==UPLOAD_ERR_OK||!is_string($f['tmp_name'])||!is_uploaded_file($f['tmp_name'])||$f['size']>Contract::MAX_FILE) { $errors[$role]='Upload a PDF, DOC or DOCX up to 10 MB.'; continue; }
                $bytes=file_get_contents($f['tmp_name'],false,null,0,Contract::MAX_FILE+1);
                if(!is_string($bytes)) { $errors[$role]='Document could not be read.'; continue; }
                $files[$role]=['name'=>$f['name'],'bytes'=>$bytes];
            }
            $errors+=$portal->validate($v,$input,$files);
            if(!$errors) { $success=$portal->submit($reference,$form['id'],$input,$files); $_SESSION['confirmation'][$reference]=$success; header('Location: /careers/'.$reference.'?submitted=1',true,303); exit; }
            http_response_code(422);
        }
        if(isset($_GET['submitted'])) $success=$_SESSION['confirmation'][$reference]??null;
    } else { http_response_code(404); throw new InvalidArgumentException('Page unavailable.'); }
} catch(InvalidArgumentException $err) { $message=$err->getMessage(); if(http_response_code()===200) http_response_code(404); }
catch(Throwable $err) { http_response_code(503); $message='Careers is temporarily unavailable. Please try again later.'; error_log('careers request failed'); }
$error=static function($key)use(&$errors,$e) { if(isset($errors[$key])) echo '<span class="error" id="error-'.$e($key).'">'.$e($errors[$key]).'</span>'; };
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Careers · Passion Technologies</title><link rel="stylesheet" href="/careers/careers.css"></head><body><header><a href="/careers">Passion Technologies <span>Careers</span></a></header><main>
<?php if($message): ?><p role="alert"><?= $e($message) ?></p>
<?php elseif($success): ?><section class="panel"><p class="eyebrow">Thank you for your interest</p><h1>Application submitted successfully</h1><p>Reference: <strong><?= $e($success) ?></strong></p><p>Your application is ready for our recruitment team to review. Please keep this reference.</p><a href="/careers">Explore other opportunities</a></section>
<?php elseif($v): ?><p class="eyebrow">Join our team</p><h1><?= $e($v['title']??'Vacancy closed') ?></h1><p><?= $e(implode(' · ',array_filter([$v['department']??'',$v['location']??'']))) ?></p><p>Closing date: <?= $e($v['closes_on']??'Open until filled') ?> (UTC)</p><div class="description"><?= nl2br($e($v['description']??'')) ?></div>
<?php if(!Contract::open($v)): ?><p>This vacancy is not accepting applications.</p><?php else: ?><a class="button" href="#apply">Apply for this position</a><form id="apply" method="post" enctype="multipart/form-data"><input type="hidden" name="_token" value="<?= $e($_SESSION['csrf']) ?>"><input type="hidden" name="revision" value="<?= $e($v['revision']) ?>"><div class="trap" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>
<?php if($errors): ?><p role="alert">Please review the highlighted fields. Select your documents again before submitting.</p><?php endif; ?>
<section class="panel"><h2>Your details</h2><p>Required fields are marked *</p><div class="grid"><?php foreach(['name'=>['Full name','text','name'],'email'=>['Email','email','email'],'phone'=>['Phone','tel','tel']] as $key=>[$label,$type,$autocomplete]): ?><label for="<?= $key ?>"><?= $label ?> *<input id="<?= $key ?>" name="<?= $key ?>" type="<?= $type ?>" autocomplete="<?= $autocomplete ?>" required maxlength="<?= $key==='email'?254:($key==='phone'?80:190) ?>" value="<?= $e(is_string($input[$key]??null)?$input[$key]:'') ?>" aria-describedby="error-<?= $key ?>" <?= isset($errors[$key])?'aria-invalid="true"':'' ?>><?php $error($key); ?></label><?php endforeach; ?></div></section>
<?php if($v['questions']): ?><section class="panel"><h2>A few questions</h2><?php foreach($v['questions'] as $c): $code=$c['code']; $type=$c['criterion_type']; $value=$input['answers'][$code]??''; $required=$c['required']?'required':''; ?><div class="question"><label for="q-<?= $e($code) ?>"><?= $e($c['label']) ?><?= $c['required']?' *':'' ?></label><p class="hint"><?= $e($c['help_text']) ?></p>
<?php if(in_array($type,['boolean','document_required','single_choice','education_level','location','multiple_choice'],true)): $options=in_array($type,['boolean','document_required'],true)?['yes'=>'Yes','no'=>'No']:array_combine($c['options'],$c['options']); ?><select id="q-<?= $e($code) ?>" name="answers[<?= $e($code) ?>]<?= $type==='multiple_choice'?'[]':'' ?>" <?= $required ?> <?= $type==='multiple_choice'?'multiple size="4"':'' ?> aria-describedby="error-<?= $e($code) ?>"><option value="">Select an answer</option><?php foreach($options as $key=>$label): ?><option value="<?= $e($key) ?>" <?= in_array((string)$key,(array)$value,true)?'selected':'' ?>><?= $e($label) ?></option><?php endforeach; ?></select>
<?php else: ?><input id="q-<?= $e($code) ?>" name="answers[<?= $e($code) ?>]" type="<?= $type==='experience_years'?'number':'text' ?>" <?= $type==='experience_years'?'min="0" max="100" step="0.1"':'maxlength="1000"' ?> value="<?= $e(is_scalar($value)?$value:'') ?>" <?= $required ?> aria-describedby="error-<?= $e($code) ?>"><?php endif; ?><?php $error($code); ?></div><?php endforeach; ?></section><?php endif; ?>
<section class="panel"><h2>Documents</h2><p class="hint">PDF, DOC or DOCX · Maximum 10 MB each. Your documents are stored privately.</p><?php $error('documents'); foreach(['cv'=>'CV','letter'=>'Application letter'] as $key=>$label): ?><label for="<?= $key ?>"><?= $label ?> *<input id="<?= $key ?>" type="file" name="<?= $key ?>" accept=".pdf,.doc,.docx" required aria-describedby="error-<?= $key ?>"><?php $error($key); ?></label><?php endforeach; ?></section>
<section class="panel"><h2>Review and confirmation</h2><p>Review your details and answers before submitting. Our recruitment team will review your application and supporting documents.</p><label class="consent"><input id="consent" type="checkbox" name="consent" value="yes" required <?= ($input['consent']??'')==='yes'?'checked':'' ?>>I confirm these details are accurate and consent to their use for this recruitment process. *</label><?php $error('consent'); ?><button type="submit">Submit application</button></section></form><?php endif; ?>
<?php else: ?><p class="eyebrow">Build your next chapter</p><h1>Careers at Passion Technologies</h1><p>Explore our open opportunities and find a role where you can make a difference.</p><?php foreach($listing as $item): ?><article class="panel"><h2><a href="/careers/<?= $e($item['reference']) ?>"><?= $e($item['title']) ?></a></h2><p><?= $e(implode(' · ',array_filter([$item['department']??'',$item['location']??'']))) ?></p><p>Closing: <?= $e($item['closes_on']??'Open until filled') ?></p><a href="/careers/<?= $e($item['reference']) ?>">View position →</a></article><?php endforeach; ?><?php if(!$listing): ?><p>No open positions on this page.</p><?php endif; ?><nav><?php $page=max(1,(int)($_GET['page']??1)); if($page>1): ?><a href="/careers?page=<?= $page-1 ?>">Previous</a><?php endif; ?> <a href="/careers?page=<?= $page+1 ?>">More opportunities</a></nav><?php endif; ?>
</main><footer>Passion Technologies · Recruitment</footer></body></html>
