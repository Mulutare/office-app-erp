<?php
declare(strict_types=1);
$workspace=$data['workspace'];$entity=$data['entity'];$list=$workspace['lists'][$entity];$path=$workspace['path'];
view('components.list-filters',['query'=>$list['query'],'path'=>$path]+$workspace['controls'][$entity]);
view('components.list-download',['query'=>$list['query'],'path'=>$path,'allowed'=>$workspace['canExport'],'actions'=>['register'=>$entity]]);
view('components.list-pagination',['query'=>$list['query'],'path'=>$path,'pagination'=>$list['pagination']]);
