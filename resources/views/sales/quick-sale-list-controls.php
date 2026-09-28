<?php
declare(strict_types=1);
$name=$data['register'];$quick=$data['quick'];
if(!isset($quick['lists'][$name]))return;
$list=$quick['lists'][$name];$path=appBasePath().'/sales/quick-sale';
$sorts=['date'=>'Date','reference'=>'Reference','agent'=>'DSA/DSP','manager'=>'Manager','shop'=>'Shop','status'=>'Status'];
if($name==='queue')$sorts=['priority'=>'Action priority']+$sorts;
view('components.list-filters',['query'=>$list['query'],'path'=>$path,'sorts'=>$sorts,
    'filters'=>['status'=>['label'=>'Status','options'=>\App\Services\Lists\FilterOptions::domain('sales_quick_sales','status')],
        'from'=>['label'=>'From','type'=>'date'],'to'=>['label'=>'To','type'=>'date'],'shop'=>['label'=>'Shop','options'=>$quick['exportLists'][$name]->options('warehouse_code','warehouse_name')]]]);
view('components.list-download',['query'=>$list['query'],'path'=>$path,'allowed'=>$quick['canExport']??false,'actions'=>['register'=>$name]]);
view('components.list-pagination',['query'=>$list['query'],'pagination'=>$list['pagination'],'path'=>$path]);
