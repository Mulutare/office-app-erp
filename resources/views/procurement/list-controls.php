<?php
declare(strict_types=1);

$list=$data['list'];
$path=$data['path'];

view(
    'components.list-filters',
    [
        'query'=>$list['query'],
        'path'=>$path,
        'hidden'=>['section'=>$data['section']],
    ]+$data['controls']
);

view(
    'components.list-download',
    [
        'query'=>$list['query'],
        'path'=>$path,
        'allowed'=>$data['canExport']??false,
        'actions'=>[
            'section'=>$data['section'],
            'register'=>$data['entity'],
        ],
    ]
);

view(
    'components.list-pagination',
    [
        'query'=>$list['query'],
        'pagination'=>$list['pagination'],
        'path'=>$path,
    ]
);