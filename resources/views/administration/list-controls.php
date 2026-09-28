<?php

declare(strict_types=1);

$listing = $data['listing'];
$path = $data['path'];
$list = $listing['list'];

view(
    'components.list-filters',
    [
        'path' => $path,
        'query' => $list['query'],
    ] + $listing['controls']
);

view(
    'components.list-download',
    [
        'path' => $path,
        'query' => $list['query'],
        'allowed' => true,
        'actions' => [
            'register' => $data['entity'],
        ],
    ]
);

if ($data['showPagination'] ?? true) {
    view(
        'components.list-pagination',
        [
            'path' => $path,
            'query' => $list['query'],
            'pagination' => $list['pagination'],
        ]
    );
}
