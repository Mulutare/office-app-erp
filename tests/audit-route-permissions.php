<?php
/** Inventory the real registry without dispatching any request. No database writes. */
require __DIR__.'/../app/helpers/bootstrap.php';
final class Router {
    public array $registered=[];
    public function get(string $path, callable $handler):void{$this->registered[]=['GET',$path,$handler];}
    public function post(string $path, callable $handler):void{$this->registered[]=['POST',$path,$handler];}
}
require __DIR__.'/../routes/web.php';
function source(ReflectionFunctionAbstract $r):string {return implode('',array_slice(file($r->getFileName()),$r->getStartLine()-1,$r->getEndLine()-$r->getStartLine()+1));}
function guards(ReflectionMethod $r,array $seen=[]):string {
    if(isset($seen[$r->getName()]))return ''; $seen[$r->getName()]=true;$s=source($r);$all=$s;
    preg_match_all('/\$this->([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/',$s,$calls);
    foreach(array_unique($calls[1]) as $method)if($r->getDeclaringClass()->hasMethod($method))$all.="\n".guards($r->getDeclaringClass()->getMethod($method),$seen);
    return $all;
}
$rows=[];$counts=[];
foreach($router->registered as [$verb,$path,$handler]){
    $r=is_array($handler)?new ReflectionMethod($handler[0],$handler[1]):new ReflectionFunction($handler);
    $body=$r instanceof ReflectionMethod?guards($r):source($r);
    preg_match_all('/[\'\"]((?:sales|inventory|finance|procurement|hr|attendance|assets|analytics|administration|dashboard|organization|audit|commercial_documents|api)\.[a-z_]+(?:\.[a-z_]+)*)[\'\"]/',$body,$matches);
    $permissions=array_values(array_unique($matches[1]));sort($permissions);
    $broad=array_intersect($permissions,['sales.view','finance.records.view','finance.records.manage','procurement.view','hr.records.view','hr.records.manage','assets.manage']);
    $class=$r instanceof ReflectionMethod?$r->getDeclaringClass()->getShortName():'closure';
    $category=$permissions!==[]?($broad!==[]?'B':'A'):'E';
    if(in_array($class,['AuthController','HomeController'],true))$category='G';
    if(in_array($class,['AuthenticatedSessionController','CompanyContextController','UserNotificationController'],true))$category='F';
    if(str_contains($body,'requireRole(')||str_contains($body,'hasRole('))$category='C';
    // Manually verified dynamic suffix and account/platform contracts.
    $prefixes=['SalesPricingController'=>'sales.pricing.','SalesIncentiveController'=>'sales.incentive.','FinanceBankReconciliationController'=>'finance.bank_reconciliation.'];
    if(isset($prefixes[$class]) && preg_match_all('/(?:permit|mutate)\\(\\s*[\'\"]([a-z_]+)[\'\"]/',$body,$suffixes)) {
        foreach($suffixes[1] as $suffix)$permissions[]=$prefixes[$class].$suffix;
        $permissions=array_values(array_unique($permissions));$category='A';
    }
    if(str_contains($body,'requirePlatformAdministrator(')){$category='A';$permissions[]='Explicit platform administrator flag';}
    if($class==='NotificationController')$category='F';
    if($path==='/api/v1/oauth/token')$category='G';
    if($path==='/account')$category='F';
    $counts[$category]=($counts[$category]??0)+1;
    $landing=App\Services\WorkspaceAccessService::forPath($path);
    $rows[]=[$verb,$path,$class.'::'.$r->getName(),$category,implode(', ',$permissions),$landing?implode(' OR ',(array)$landing[2]):'Controller contract'];
}
$out="# Registered-route permission inventory 094\n\nGenerated from every registered route and reflected controller/helper source. This is a static inventory, not a claim that every workflow and data query has passed end-to-end review. A = explicit function grant found; B = broad grant remains and needs semantic review (the final column records added landing gates); C = role check; D = navigation-only; E = no literal permission resolved, manual review required; F = authenticated account/system route; G = public/auth route. Indirect service authorization and dynamic permission suffixes require manual review.\n\n";
$out.='Routes: '.count($rows).'. Classification totals: '.json_encode($counts).".\n\n| Method | Route | Handler | Class | Controller/helper permission evidence | Shared landing gate |\n|---|---|---|---|---|---|\n";
foreach($rows as $row)$out.='| '.implode(' | ',array_map(fn($v)=>str_replace('|',' / ',$v),$row))." |\n";
file_put_contents(__DIR__.'/../docs/FUNCTION_PERMISSION_AUDIT_094.md',$out);
echo json_encode(['routes'=>count($rows),'classifications'=>$counts]),PHP_EOL;
foreach($rows as $row)if($row[3]==='E')echo implode(' ',array_slice($row,0,4)),PHP_EOL;
