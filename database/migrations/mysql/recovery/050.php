<?php
declare(strict_types=1);
return static function(PDO $c):string{
    $route=$c->query("SELECT route_path FROM erp_modules WHERE code='assets'")->fetchColumn();
    if($route==='/assets-management')return'baseline';
    if(is_string($route)&&$route!=='')return'apply';
    throw new RuntimeException('Migration 050 could not find the Assets module route.');
};
