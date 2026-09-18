<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\SalesStockHistoryService;

final class SalesStockHistoryController
{
    public function index(): void
    {
        (new AuthorizationService())->requireModulePermission('inventory','inventory.stock.view');
        try{$history=(new SalesStockHistoryService())->report((int)($_SESSION['auth']['user_id']??0),$_GET);$error=null;}
        catch(\Throwable $e){$history=[];$error=$e->getMessage();}
        \view('layouts.app',['applicationName'=>\config('name','OfficeApp ERP'),'environment'=>\config('environment','unknown'),'pageTitle'=>'Stock Daily Movement','pageDescription'=>'Completed ledger endpoint legs, reservation cutover and balance discrepancies.','contentView'=>'inventory.stock-daily-history','user'=>$_SESSION['auth'],'stockHistory'=>$history,'error'=>$error]);
    }
}
