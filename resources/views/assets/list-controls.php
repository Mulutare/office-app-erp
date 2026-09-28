<?php
declare(strict_types=1);
$entity=$data['entity'];$workspace=$data['workspace'];$list=$workspace['lists'][$entity];
$path=appBasePath().'/assets-management'.(isset($workspace['asset'])?'/'.(int)$workspace['asset']['asset_id']:'');
view('components.list-filters',['query'=>$list['query'],'path'=>$path]+$workspace['controls'][$entity]);
view('components.list-download',['query'=>$list['query'],'path'=>$path,'allowed'=>true,'actions'=>['register'=>$entity]]);
view('components.list-pagination',['query'=>$list['query'],'path'=>$path,'pagination'=>$list['pagination']]);
