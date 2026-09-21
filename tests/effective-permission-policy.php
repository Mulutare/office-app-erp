<?php
require __DIR__.'/../app/services/EffectivePermissionPolicy.php';
use App\Services\EffectivePermissionPolicy as Policy;
$checks=0;
$check=function(bool $condition,string $message)use(&$checks){$checks++;if(!$condition)throw new RuntimeException($message);echo "PASS $message\n";};
$grant=fn(int $role,string $code)=>['role_id'=>$role,'code'=>$code];
$child=$grant(1,'sales.incentive.view');$gate=$grant(1,'sales.module.enabled');
$check(Policy::resolve([$child],['sales'])===[],'Child grant without module gate is ineffective');
$check(in_array('sales.incentive.view',Policy::resolve([$child,$gate],['sales']),true),'Same-role module gate enables child grant');
$check(Policy::resolve([$child,$gate],[])===[],'Company-disabled module denies all child grants');
$check(!in_array('sales.incentive.view',Policy::resolve([$child,$grant(2,'sales.module.enabled')],['sales']),true),'Different role cannot activate dormant child grant');
$check(in_array('sales.incentive.view',Policy::resolve([$child,$gate],['sales']),true),'Re-enabling gate restores preserved child grant');
$check(!in_array('finance.records.view',Policy::resolve([$child,$gate,$grant(1,'finance.records.view')],['sales','finance']),true),'Sales gate does not activate Finance');
$check(in_array('audit.logs.view',Policy::resolve([$grant(9,'audit.logs.view'),$grant(9,'administration.module.enabled')],['administration']),true),'Audit functions use Administration gate');
$check(Policy::resolve([['role_id'=>1,'code'=>'commercial_documents.download','module'=>'sales']],['sales'])===[],'Permission catalogue module governs cross-namespace grants');

$override=fn(string $code,bool $allowed)=>['code'=>$code,'allowed'=>$allowed];
$check(!in_array('sales.incentive.view',Policy::resolve([$child,$gate],['sales'],[$override('sales.incentive.view',false)]),true),'User Deny removes inherited grant');
$check(in_array('sales.settlements.view',Policy::resolve([$gate],['sales'],[$override('sales.settlements.view',true)]),true),'User Allow adds capability without editing role');
$check(Policy::resolve([$gate],[],[$override('sales.module.enabled',true),$override('sales.quick_sale.use',true)])===[],'Company boundary defeats both user module and function Allow');
$check(Policy::resolve([$child,$gate],['sales'],[$override('sales.module.enabled',false),$override('sales.incentive.view',true)])===[],'User module Deny defeats user function Allow');

echo "$checks policy checks passed\n";
