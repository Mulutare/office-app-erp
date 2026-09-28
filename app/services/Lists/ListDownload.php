<?php
declare(strict_types=1);
namespace App\Services\Lists;

use App\Services\DataExchange\ExportService;

/** Controllers must pass their normal view gate and explicit export gate first. */
final class ListDownload
{
    public static function send(string $name, SqlList $list, array $columns, mixed $format): never
    {
        try {
            $file=(new ExportService())->register($name,ListQuery::text($format),$columns,$list->export());
        } catch (\Throwable $error) {
            http_response_code(400);echo \e($error->getMessage());exit;
        }
        header('Content-Type: '.$file['mime']);
        header('Content-Disposition: attachment; filename="'.$file['filename'].'"');
        header('X-Content-Type-Options: nosniff');
        echo $file['contents'];exit;
    }
}
