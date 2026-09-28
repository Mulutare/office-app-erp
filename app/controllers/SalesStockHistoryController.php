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
        try{$history=(new SalesStockHistoryService())->report((int)($_SESSION['auth']['user_id']??0),$_GET,true);$error=null;}
        catch(\Throwable $e){$history=[];$error=$e->getMessage();}
        if(isset($_GET['download'])) {
            (new AuthorizationService())->requireTenantPermission('inventory.export');
            $entity=\App\Services\Lists\ListQuery::text($_GET['register']??'');
            if(!isset($history['exportLists'][$entity])){http_response_code(400);echo \e($error??'Unknown stock history register.');return;}
            \App\Services\Lists\ListDownload::send('stock-history-'.$entity,$history['exportLists'][$entity],\App\Services\Lists\StockHistoryListService::columns($entity),$_GET['download']);
        }
        \view('layouts.app',['applicationName'=>\config('name','OfficeApp ERP'),'environment'=>\config('environment','unknown'),'pageTitle'=>'Stock Daily Movement','pageDescription'=>'Completed ledger endpoint legs, reservation cutover and balance discrepancies.','contentView'=>'inventory.stock-daily-history','user'=>$_SESSION['auth'],'stockHistory'=>$history,'error'=>$error]);
    }
}
