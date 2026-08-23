<?php
declare(strict_types=1);
namespace App\Services;

use App\Repositories\MySql\SettlementRepository;

final class SalesSettlementDocumentService
{
    public function __construct(private ?SettlementRepository $repo=null,private ?PdfDocumentService $pdf=null){$this->repo??=new SettlementRepository();$this->pdf??=new PdfDocumentService();}
    public function settlement(int $companyId,int $id,string $type): array
    {
        $s=$this->repo->find($companyId,$id);if($s===null)throw new \RuntimeException('Settlement was not found.');$branding=$this->repo->branding($companyId);$recon=$type==='reconciliation';if($recon&&!in_array($s['workflow_status'],['finance_reconciled','approved','closed'],true))throw new \RuntimeException('Reconciliation PDF is available after Finance reconciliation.');
        $title=$recon?'ERP Settlement Reconciliation':'Sales Settlement / Bank Deposit Advice';$body=$this->header($branding,$title,$s['settlement_number']).'<p><b>Bank:</b> '.htmlspecialchars($s['bank_name']).' · <b>Account:</b> '.htmlspecialchars($s['account_name'].' '.$s['account_number']).' · <b>Currency:</b> '.htmlspecialchars($s['currency']).'</p><table class="grid"><thead><tr><th>Sales order</th><th>Customer</th><th>Payment</th><th>Date</th><th class="money">Amount</th></tr></thead><tbody>';
        foreach($s['lines'] as $l)$body.='<tr><td>'.htmlspecialchars($l['order_number']).'</td><td>'.htmlspecialchars($l['customer_name']).'</td><td>'.htmlspecialchars($l['payment_number']).'</td><td>'.htmlspecialchars($l['payment_date']).'</td><td class="money">'.number_format((float)$l['amount'],2).'</td></tr>';
        $body.='</tbody></table><table class="grid totals"><tr><th>Expected deposit</th><td class="money">'.number_format((float)$s['expected_amount'],2).'</td></tr>';
        if($recon)$body.='<tr><th>Bank confirmed</th><td class="money">'.number_format((float)$s['confirmed_amount'],2).'</td></tr><tr><th>Variance</th><td class="money">'.number_format((float)$s['variance_amount'],2).'</td></tr><tr><th>Remaining</th><td class="money">'.number_format((float)$s['remaining_amount'],2).'</td></tr><tr><th>Status</th><td>'.htmlspecialchars(strtoupper(str_replace('_',' ',$s['reconciliation_status']))).'</td></tr>';
        $body.='</table><p><b>Amount in words:</b> '.htmlspecialchars((new EtbAmountInWords())->convert($s['expected_amount'])).'</p>';
        if($recon)$body.='<h2>Confirmation and approvals</h2><p>Bank references: '.htmlspecialchars(implode(', ',array_column($s['confirmations'],'bank_reference'))).'</p><p>Supervisor reviewed: '.htmlspecialchars((string)($s['supervisor_reviewed_at']??'—')).' · Finance reconciled: '.htmlspecialchars((string)($s['finance_reconciled_at']??'—')).' · Approved: '.htmlspecialchars((string)($s['approved_at']??'—')).'</p><p class="muted">This ERP reconciliation is separate from the original bank-issued receipt.</p>';
        return ['filename'=>($recon?'RECON-':'DEPOSIT-ADVICE-').$s['settlement_number'].'.pdf','content'=>$this->pdf->render($title,$body)];
    }
    private function header(array $b,string $title,string $number): string{$name=(string)($b['legal_name']?:$b['company_name']??'Company');return '<h1>'.htmlspecialchars($name).'</h1><p class="muted">'.htmlspecialchars((string)($b['document_address']??'')).' '.htmlspecialchars((string)($b['document_phone']??$b['contact_phone']??'')).'<br>TIN: '.htmlspecialchars((string)($b['tin']??'—')).' · VAT: '.htmlspecialchars((string)($b['vat_registration_number']??'—')).'</p><h2>'.$title.'</h2><p><b>Document:</b> '.htmlspecialchars($number).' · <b>Date:</b> '.date('Y-m-d').'</p>';}
}
