<?php
declare(strict_types=1);
require_once __DIR__.'/../app/helpers/bootstrap.php';
use App\Services\Lists\AssetListService;
use App\Services\DataExchange\ExportService;
if(getenv('APP_ENV')!=='testing')throw new RuntimeException('Isolated test database required.');
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL);exit(1);});
$db=db();$checks=0;$assert=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);++$checks;echo 'PASS '.$message.PHP_EOL;};
$context=$db->query("SELECT cr.company_id,cr.user_id FROM company_user_roles cr JOIN roles r ON r.role_id=cr.role_id WHERE r.code='company_owner' ORDER BY cr.company_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$company=(int)$context['company_id'];$actor=(int)$context['user_id'];$_SESSION['auth']=['user_id'=>$actor,'company'=>['company_id'=>$company]];
$prefix='AST'.strtoupper(bin2hex(random_bytes(3)));$factory=new AssetListService();$db->beginTransaction();
try {
    $db->prepare("INSERT INTO finance_accounts(company_id,account_code,account_name,account_type,normal_balance) VALUES(?,?,?,'asset','debit')")->execute([$company,$prefix,$prefix]);$account=(int)$db->lastInsertId();
    $category=$db->prepare('INSERT INTO asset_categories(company_id,category_code,category_name,asset_account_id,accumulated_depreciation_account_id,depreciation_expense_account_id,disposal_gain_account_id,disposal_loss_account_id,useful_life_months) VALUES(?,?,?,?,?,?,?,?,120)');
    $asset=$db->prepare("INSERT INTO fixed_assets(company_id,asset_category_id,asset_number,asset_name,acquisition_date,acquisition_cost,book_value,currency,depreciation_method,useful_life_months,depreciation_frequency,serial_number,location_name) VALUES(?,?,?,?,'2024-01-01',1200,1200,'ETB','straight_line',120,'monthly',?,?)");
    for($i=1;$i<=105;++$i){$code=$prefix.sprintf('%03d',$i);$category->execute([$company,$code,$code,$account,$account,$account,$account,$account]);$cat=(int)$db->lastInsertId();$asset->execute([$company,$cat,$code,$code,'SN-'.$code,'Room '.sprintf('%03d',$i)]);$assets[$i]=(int)$db->lastInsertId();}
    foreach(['register'=>'assets','categories'=>'categories'] as $entity=>$namespace){
        foreach([25,50,100] as $size){$page=$factory->listing($entity,[$namespace=>['q'=>$prefix,'per_page'=>$size]])->page();$assert($page['pagination']['total']===105 && count($page['rows'])===$size,"$entity counts before page size $size");}
        $assert($factory->listing($entity,[$namespace=>['q'=>$prefix.'105']])->page()['pagination']['total']===1,"$entity searches beyond page one");
        $assert($factory->listing($entity,[$namespace=>['q'=>'no-such-asset']])->export()===[],"$entity empty export remains empty");
        $page=$factory->listing($entity,[$namespace=>['q'=>$prefix,'page'=>2]])->page();$assert($page['pagination']['from']===26,"$entity page two starts at 26");
        $assert(count($factory->listing($entity,[$namespace=>['q'=>$prefix]])->export())===105,"$entity export includes all filtered pages");
    }
    $controls=$factory->controls('register',$factory->listing('register',['assets'=>['q'=>'none']]));
    $assert(isset($controls['filters']['status']['options']['active'],$controls['filters']['status']['options']['disposed']),'Asset lifecycle statuses come from the full SQL domain');
    $assert(count($controls['filters']['location']['options'])===105,'Asset locations are retrieved before search or pagination');
    $workspace=$factory->workspace(['assets'=>['q'=>$prefix]]);$assert((int)$workspace['summary']['total']===105 && (float)$workspace['summary']['cost']===126000.0,'Asset totals cover all matching records');
    $assert(count($workspace['categoryOptions'])>=105 && count($workspace['categories'])===25,'Asset workflow selectors remain complete when the category register is paged');
    $history=$db->prepare("INSERT INTO asset_history(company_id,asset_id,action,details_json,actor_id,occurred_at) VALUES(?,?,'tracking_updated',?,?,'2024-01-01')");
    $schedule=$db->prepare("INSERT INTO asset_depreciation_schedule(company_id,asset_id,period_number,depreciation_date,depreciation_amount,accumulated_amount,book_value_after) VALUES(?,?,?,?,10,?,?)");
    $transfer=$db->prepare("INSERT INTO asset_transfers(company_id,asset_id,to_location_name,transferred_at,reason,transferred_by) VALUES(?,?,?,'2024-01-01',?,?)");
    $maintenance=$db->prepare("INSERT INTO asset_maintenance_records(company_id,asset_id,maintenance_type,description,maintenance_date,created_by) VALUES(?,?,'inspection',?,'2024-01-01',?)");
    for($i=1;$i<=105;++$i){$history->execute([$company,$assets[1],json_encode(['presence_status'=>'present']),$actor]);$schedule->execute([$company,$assets[1],$i,(new DateTimeImmutable('2024-01-01'))->modify('+'.$i.' months')->format('Y-m-d'),$i*10,1200-$i*10]);$transfer->execute([$company,$assets[1],'Room '.$i,$prefix.sprintf('%03d',$i),$actor]);$maintenance->execute([$company,$assets[1],$prefix.sprintf('%03d',$i),$actor]);}
    foreach(['schedule','transfers','maintenance','history'] as $entity){$list=$factory->listing($entity,[],$assets[1]);$assert($list->page()['pagination']['total']===105 && count($list->page()['rows'])===25,"$entity detail history paginates");$assert(count($list->export())===105,"$entity export covers all matching records");$assert($factory->listing($entity,[],$assets[2])->export()===[],"$entity is restricted to its selected asset");}
    $assert($factory->listing('maintenance',['maintenance'=>['q'=>$prefix.'105']],$assets[1])->page()['pagination']['total']===1,'Maintenance search reaches a later page');
    $assert($factory->listing('schedule',['schedule'=>['status'=>'posted']],$assets[1])->page()['pagination']['total']===0,'Depreciation status filters before count');
    foreach(['csv','xlsx'] as $format){$file=(new ExportService())->register('assets',$format,$factory->columns('register'),$factory->listing('register',['assets'=>['q'=>$prefix]])->export());$assert(strlen($file['contents'])>1000,"$format serializes full business asset export");}
    $_GET=['section'=>'register'];ob_start();view('assets.index',$workspace+['user'=>['permissions'=>['assets.manage']]]);$html=(string)ob_get_clean();$assert(str_contains($html,'name="assets[q]"') && str_contains($html,'register=register'),'Asset register renders independent query and export keys');
    $detail=$factory->workspace([],$assets[1]);ob_start();view('assets.show',$detail+['user'=>['permissions'=>['assets.manage']]]);$html=(string)ob_get_clean();$assert(str_contains($html,'name="history[q]"') && str_contains($html,'name="schedule[q]"'),'Asset detail histories have independent namespaces');
    $_SESSION['auth']['company']['company_id']=$company+100000;$denied=false;try{$denied=$factory->listing('register',[])->export()===[];}catch(RuntimeException $e){$denied=true;}$assert($denied,'Foreign company cannot read or export asset records');
} finally {if($db->inTransaction())$db->rollBack();}
echo "$checks asset smart-list checks passed".PHP_EOL;
