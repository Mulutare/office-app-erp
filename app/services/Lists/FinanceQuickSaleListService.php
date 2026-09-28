<?php
declare(strict_types=1);
namespace App\Services\Lists;

/** Uses QuickSaleRouting's existing Finance-reader and handoff authority. */
final class FinanceQuickSaleListService
{
    private const SORTS=['date'=>'finance_handoff_at','invoice'=>'invoice_number','order'=>'order_number','customer'=>'customer_name','agent'=>'agent_name','manager'=>'manager_name','status'=>'invoice_status'];
    private const FILTERS=['status'=>'invoice_status','payment'=>'payment_status','currency'=>'currency','from'=>['DATE(finance_handoff_at)','>='],'to'=>['DATE(finance_handoff_at)','<=']];

    public function listing(int $actor,array $input): SqlList
    {
        [$sql,$params]=(new \App\Services\SalesQuickSaleService())->financeQueueDefinition($actor);
        return new SqlList(\db(),$sql,$params,new ListQuery($input,self::SORTS,'date',array_keys(self::FILTERS),'desc','quick_sales'),
            ['invoice_number','quotation_number','order_number','customer_name','invoice_reference','payment_reference','agent_name','manager_name'],self::SORTS,'report_id',self::FILTERS);
    }

    public function controls(int $actor): array
    {
        return FilterOptions::controls($this->listing($actor,[]),self::FILTERS,self::SORTS,
            ['status'=>['finance_invoices','status'],'payment'=>['finance_invoices','payment_status']]);
    }

    public static function columns(): array
    {
        return ['finance_handoff_at'=>'Handed to Finance','invoice_number'=>'Invoice','quotation_number'=>'Quotation','order_number'=>'Order',
            'customer_name'=>'Customer','total_amount'=>'Total','currency'=>'Currency','invoice_status'=>'Invoice status','payment_status'=>'Payment status',
            'invoice_reference'=>'DSA receipt','payment_method'=>'DSA payment method','payment_reference'=>'DSA payment reference','agent_name'=>'DSA / DSP','manager_name'=>'Manager','has_evidence'=>'Has receipt evidence'];
    }
}
