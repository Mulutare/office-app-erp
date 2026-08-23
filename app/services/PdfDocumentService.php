<?php
declare(strict_types=1);
namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

final class PdfDocumentService
{
    public function render(string $title,string $body): string
    {
        if(!class_exists(Dompdf::class))throw new \RuntimeException('The approved PDF renderer is not installed.');
        $options=new Options();$options->set('isRemoteEnabled',false);$options->set('isPhpEnabled',false);$dompdf=new Dompdf($options);
        $html='<!doctype html><html><head><meta charset="utf-8"><style>@page{margin:34px 38px 48px}body{font:11px DejaVu Sans;color:#172033}h1{font-size:21px;margin:0 0 5px}h2{font-size:13px;border-bottom:1px solid #ccd3df;padding-bottom:5px}.muted{color:#64748b}.badge{padding:4px 8px;border:1px solid #64748b}.grid{width:100%;border-collapse:collapse;margin:12px 0}.grid th,.grid td{border:1px solid #d5dae3;padding:6px;text-align:left}.grid th{background:#eef2f7}.money{text-align:right}.totals{margin-left:auto;width:48%}.footer{position:fixed;bottom:-30px;width:100%;text-align:center;color:#64748b;font-size:9px}</style><title>'.htmlspecialchars($title).'</title></head><body>'.$body.'<div class="footer">OfficeApp ERP · '.htmlspecialchars($title).' · <span class="page-number"></span></div></body></html>';
        $dompdf->loadHtml($html,'UTF-8');$dompdf->setPaper('A4');$dompdf->render();return $dompdf->output();
    }
}
