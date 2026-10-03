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
$described=static function(string $key,string $hint='')use(&$errors,$e): string {
    $ids=trim($hint.' '.(isset($errors[$key])?'error-'.$key:''));
    return ($ids!==''?' aria-describedby="'.$e($ids).'"':'').(isset($errors[$key])?' aria-invalid="true"':'');
};
$page=max(1,(int)($_GET['page']??1));
$companyUrl='https://passiontechnologiesplc.com/';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Careers · Passion Technologies</title>
    <link rel="stylesheet" href="/careers/careers.css">
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header">
    <div class="header-inner">
        <a class="brand" href="/careers" aria-label="Passion Technologies Careers"><span class="brand-mark" aria-hidden="true">P<span>.</span></span><span class="brand-name">Passion Technologies<span class="brand-caption">People. Possibilities. Progress.</span></span></a>
        <nav class="header-nav" aria-label="Main navigation"><a class="nav-current" href="/careers" aria-current="location">Careers</a><a class="company-link" href="<?= $e($companyUrl) ?>">Company website <span aria-hidden="true">↗</span></a></nav>
    </div>
</header>
<main id="main" tabindex="-1">
<?php if($message): ?>
    <section class="panel message-panel"><p class="eyebrow">Passion Technologies · Careers</p><h1>Let’s get you back on track</h1><p class="lead" role="alert"><?= $e($message) ?></p><a class="button" href="/careers">Back to Careers <span aria-hidden="true">→</span></a></section>
<?php elseif($success): ?>
    <section class="panel confirmation-panel">
        <span class="status-icon" aria-hidden="true">✓</span><p class="eyebrow">Your next chapter starts here</p><h1>Application submitted successfully</h1>
        <p class="lead">Thank you for your interest in Passion Technologies. Your application and documents are ready for our recruitment team to review.</p>
        <div class="reference-box"><span>YOUR APPLICATION REFERENCE</span><strong><?= $e($success) ?></strong><p>Please keep this reference for your records.</p></div>
        <div class="button-row"><a class="button" href="/careers">Back to Careers <span aria-hidden="true">→</span></a><a class="text-link" href="<?= $e($companyUrl) ?>">Visit our company website ↗</a></div>
    </section>
<?php elseif($v): ?>
    <a class="back-link" href="/careers"><span aria-hidden="true">←</span> All opportunities</a>
    <section class="vacancy-hero">
        <p class="eyebrow">An opportunity to make a difference</p><h1><?= $e($v['title']??'Vacancy closed') ?></h1>
        <div class="vacancy-meta"><?php foreach(['department'=>'Department','location'=>'Location'] as $key=>$label): if(!empty($v[$key])): ?><span><span class="meta-label"><?= $label ?></span><?= $e($v[$key]) ?></span><?php endif; endforeach; ?><span><span class="meta-label">Closing date</span><?= $e($v['closes_on']??'Open until filled') ?><?= !empty($v['closes_on'])?' · UTC':'' ?></span></div>
        <?php if(Contract::open($v)): ?><a class="button button-light" href="#apply">Apply for this position <span aria-hidden="true">→</span></a><?php endif; ?>
    </section>
    <?php if(!empty($v['description'])): ?><section class="description-panel"><p class="eyebrow">The opportunity</p><h2>About the role</h2><div class="description"><?= nl2br($e($v['description'])) ?></div></section><?php endif; ?>
    <?php if(!Contract::open($v)): ?><section class="panel closed-panel"><span class="small-label">Applications closed</span><h2>Thank you for your interest</h2><p>This vacancy is not accepting applications. Explore our Careers page for other opportunities.</p><a class="text-link" href="/careers">Back to Careers <span aria-hidden="true">→</span></a></section>
    <?php else: ?>
    <div class="application-heading" id="apply"><div><p class="eyebrow">Take the next step</p><h2>Your application</h2></div><p>Tell us about yourself.<br>Required fields are marked <span class="required">*</span></p></div>
    <form class="application-form" method="post" enctype="multipart/form-data" aria-label="Apply for this position">
        <input type="hidden" name="_token" value="<?= $e($_SESSION['csrf']) ?>"><input type="hidden" name="revision" value="<?= $e($v['revision']) ?>">
        <div class="trap" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>
        <?php if($errors): ?><div class="validation-summary" role="alert"><strong>A few details need your attention.</strong><p>Please review the highlighted fields. Select your documents again before submitting.</p></div><?php endif; ?>
        <section class="form-section" aria-labelledby="details-heading"><div class="section-heading"><span class="section-number" aria-hidden="true">01</span><div><h2 id="details-heading">Your details</h2><p>How our recruitment team can reach you.</p></div></div>
            <div class="grid details-grid"><?php foreach(['name'=>['Full name','text','name'],'email'=>['Email','email','email'],'phone'=>['Phone','tel','tel']] as $key=>[$label,$type,$autocomplete]): ?>
                <div class="field <?= $key==='name'?'field-wide':'' ?>"><label for="<?= $key ?>"><?= $label ?> <span class="required">*</span></label><input id="<?= $key ?>" name="<?= $key ?>" type="<?= $type ?>" autocomplete="<?= $autocomplete ?>" required maxlength="<?= $key==='email'?254:($key==='phone'?80:190) ?>" value="<?= $e(is_string($input[$key]??null)?$input[$key]:'') ?>"<?= $described($key) ?>><?php $error($key); ?></div>
            <?php endforeach; ?></div>
        </section>
        <?php if($v['questions']): ?><section class="form-section" aria-labelledby="questions-heading"><div class="section-heading"><span class="section-number" aria-hidden="true">02</span><div><h2 id="questions-heading">A few questions</h2><p>Help us understand your experience and suitability for this role.</p></div></div><?php $error('answers'); ?>
            <?php foreach($v['questions'] as $c): $code=$c['code']; $type=$c['criterion_type']; $value=$input['answers'][$code]??''; $required=$c['required']?'required':''; $hint=(!empty($c['help_text'])||$type==='multiple_choice')?'hint-'.$code:''; ?>
                <div class="field question"><label for="q-<?= $e($code) ?>"><?= $e($c['label']) ?><?= $c['required']?' <span class="required">*</span>':'' ?></label>
                <?php if($hint): ?><p class="hint" id="<?= $e($hint) ?>"><?= $e($c['help_text']) ?><?= $type==='multiple_choice'?' Select all that apply. On a keyboard, hold Ctrl (Windows) or Command (Mac) to choose multiple options.':'' ?></p><?php endif; ?>
                <?php if(in_array($type,['boolean','document_required','single_choice','education_level','location','multiple_choice'],true)): $options=in_array($type,['boolean','document_required'],true)?['yes'=>'Yes','no'=>'No']:array_combine($c['options'],$c['options']); ?>
                    <select id="q-<?= $e($code) ?>" name="answers[<?= $e($code) ?>]<?= $type==='multiple_choice'?'[]':'' ?>" <?= $required ?> <?= $type==='multiple_choice'?'multiple size="4"':'' ?><?= $described($code,$hint) ?>><?php if($type!=='multiple_choice'): ?><option value="">Select an answer</option><?php endif; ?><?php foreach($options as $key=>$label): ?><option value="<?= $e($key) ?>" <?= in_array((string)$key,(array)$value,true)?'selected':'' ?>><?= $e($label) ?></option><?php endforeach; ?></select>
                <?php else: ?><input id="q-<?= $e($code) ?>" name="answers[<?= $e($code) ?>]" type="<?= $type==='experience_years'?'number':'text' ?>" <?= $type==='experience_years'?'min="0" max="100" step="0.1"':'maxlength="1000"' ?> value="<?= $e(is_scalar($value)?$value:'') ?>" <?= $required ?><?= $described($code,$hint) ?>><?php endif; ?><?php $error($code); ?></div>
            <?php endforeach; ?>
        </section><?php endif; ?>
        <section class="form-section" aria-labelledby="documents-heading"><div class="section-heading"><span class="section-number" aria-hidden="true"><?= $v['questions']?'03':'02' ?></span><div><h2 id="documents-heading">Documents</h2><p id="document-hint">PDF, DOC or DOCX · Maximum 10 MB each. Your documents are stored privately.</p></div></div><?php $error('documents'); ?>
            <div class="grid uploads"><?php foreach(['cv'=>['CV','Your experience, skills and achievements.'],'letter'=>['Application letter','Tell us why this opportunity is right for you.']] as $key=>[$label,$hint]): ?><div class="upload-field"><span class="document-symbol" aria-hidden="true">↑</span><label for="<?= $key ?>"><?= $label ?> <span class="required">*</span></label><p class="hint"><?= $hint ?></p><input id="<?= $key ?>" type="file" name="<?= $key ?>" accept=".pdf,.doc,.docx" required<?= $described($key,'document-hint') ?>><?php $error($key); ?></div><?php endforeach; ?></div>
        </section>
        <section class="form-section confirmation-section" aria-labelledby="review-heading"><div class="section-heading"><span class="section-number" aria-hidden="true"><?= $v['questions']?'04':'03' ?></span><div><h2 id="review-heading">Review and confirmation</h2><p>Please check your details, answers and selected documents before submitting.</p></div></div>
            <div class="consent"><input id="consent" type="checkbox" name="consent" value="yes" required <?= ($input['consent']??'')==='yes'?'checked':'' ?><?= $described('consent') ?>><label for="consent">I confirm these details are accurate and consent to their use for this recruitment process. <span class="required">*</span></label></div><?php $error('consent'); ?>
            <div class="submit-row"><p>You’ll receive a confirmation and application reference on the next page.</p><button type="submit">Submit application <span aria-hidden="true">→</span></button></div>
        </section>
    </form><?php endif; ?>
<?php else: ?>
    <section class="careers-hero"><div class="hero-copy"><p class="eyebrow">Careers at Passion Technologies</p><h1>Build your future<br>with Passion Technologies<span class="accent">.</span></h1><p class="lead">We’re always interested in talented people who want to grow, solve meaningful problems and contribute to our team.</p><a class="button button-light" href="<?= $listing?'#opportunities':$e($companyUrl) ?>"><?= $listing?'Explore open positions':'Explore our company' ?> <span aria-hidden="true"><?= $listing?'↓':'↗' ?></span></a></div><aside class="hero-note"><span class="hero-note-line" aria-hidden="true"></span><p class="small-label">Your next chapter</p><h2>Bring your ambition.<br> Make a difference.</h2><p>Discover opportunities to put your skills to work with Passion Technologies.</p></aside></section>
    <section class="opportunities" id="opportunities" aria-labelledby="opportunities-heading"><div class="listing-heading"><div><p class="eyebrow">Explore opportunities</p><h2 id="opportunities-heading"><?= $listing?'Find your next role':'Stay connected with us' ?></h2></div><?php if($listing): ?><span class="results-label"><?= count($listing) ?> <?= count($listing)===1?'opportunity':'opportunities' ?> on this page</span><?php endif; ?></div>
    <?php if(!$listing): ?><div class="empty-state"><div class="empty-mark" aria-hidden="true">↗</div><div class="empty-copy"><span class="small-label">The right opportunity is worth the wait</span><h3><?= $page===1?'There are currently no open positions.':'No further positions on this page.' ?></h3><p><?= $page===1?'Please check back soon. In the meantime, get to know Passion Technologies and what we do.':'Return to Careers to explore the opportunities currently listed.' ?></p><a class="text-link" href="<?= $page===1?$e($companyUrl):'/careers' ?>"><?= $page===1?'Visit our company website':'Back to Careers' ?> <span aria-hidden="true">→</span></a></div></div>
    <?php else: ?><div class="vacancy-list"><?php foreach($listing as $item): ?><article class="vacancy-card"><div class="vacancy-card-main"><span class="small-label"><?= $e($item['department']?:'Passion Technologies') ?></span><h3><a href="/careers/<?= $e($item['reference']) ?>"><?= $e($item['title']) ?></a></h3><div class="card-meta"><?php if(!empty($item['location'])): ?><span><?= $e($item['location']) ?></span><?php endif; ?><span><?= !empty($item['closes_on'])?'Apply by '.$e($item['closes_on']).' · UTC':'Open until filled' ?></span></div></div><a class="card-action" href="/careers/<?= $e($item['reference']) ?>" aria-label="View position: <?= $e($item['title']) ?>">View position <span aria-hidden="true">→</span></a></article><?php endforeach; ?></div><?php endif; ?>
    <?php if($listing && ($page>1||count($listing)===30)): ?><nav class="pagination" aria-label="Vacancy pages"><?php if($page>1): ?><a href="/careers?page=<?= $page-1 ?>">← Previous page</a><?php endif; ?><span>Page <?= $page ?></span><?php if(count($listing)===30): ?><a href="/careers?page=<?= $page+1 ?>">More opportunities →</a><?php endif; ?></nav><?php endif; ?>
    </section>
<?php endif; ?>
</main>
<footer class="site-footer"><div><strong>Passion Technologies</strong><span>People and possibilities.</span></div><nav aria-label="Footer navigation"><a href="/careers">Careers</a><a href="<?= $e($companyUrl) ?>">Company website ↗</a></nav><p>Thank you for considering a future with us.</p></footer>
</body>
</html>