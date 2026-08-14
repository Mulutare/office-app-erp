<?php

declare(strict_types=1);

namespace App\Services\DataExchange;

use InvalidArgumentException;

final class ExportDefinitionRegistry
{
    /** @return array{filename:string,fields:list<ExchangeField>} */
    public function get(string $entity): array
    {
        $definitions = [
            'suppliers'=>['Suppliers',[$this->f('supplier_code','Code'),$this->f('business_name','Business Name'),$this->f('currency','Currency'),$this->f('payment_terms_days','Terms (Days)','integer'),$this->f('status','Status')]],
            'customers'=>['Customers',[$this->f('customer','Customer'),$this->f('type','Type'),$this->f('contact','Contact'),$this->f('sales_assignment','Sales Assignment'),$this->f('credit_mode','Credit Mode'),$this->f('credit_limit','Credit Limit','decimal'),$this->f('status','Status')]],
            'products'=>['Products',[$this->f('product','Product'),$this->f('category','Category'),$this->f('semantics','Semantics'),$this->f('uom','UoM'),$this->f('sales_price','Sales Price','decimal'),$this->f('status','Status')]],
            'pricelists'=>['Pricelists',[$this->f('name','Name'),$this->f('currency','Currency'),$this->f('valid_from','Valid From','date'),$this->f('valid_to','Valid To','date'),$this->f('rules','Rules','integer'),$this->f('status','Status')]],
            'sales-teams'=>['Sales_Teams',[$this->f('team','Team'),$this->f('leader','Leader'),$this->f('members','Members','integer'),$this->f('status','Status')]],
            'quotations'=>['Sales_Quotations',[$this->f('quotation','Quotation'),$this->f('customer','Customer'),$this->f('date','Date','date'),$this->f('expiry','Expiry','date'),$this->f('salesperson','Salesperson'),$this->f('sales_team','Sales Team'),$this->f('currency','Currency'),$this->f('total','Total','decimal'),$this->f('status','Status')]],
            'sales-orders'=>['Sales_Orders',[$this->f('order','Order'),$this->f('customer','Customer'),$this->f('date','Date','date'),$this->f('due','Due','date'),$this->f('currency','Currency'),$this->f('total','Total','decimal'),$this->f('paid','Paid','decimal'),$this->f('balance','Balance','decimal'),$this->f('status','Status')]],
            'warehouses'=>['Warehouses',[$this->f('warehouse','Warehouse'),$this->f('type','Type'),$this->f('branch','Branch'),$this->f('manager','Manager'),$this->f('default','Default'),$this->f('negative_stock','Negative Stock'),$this->f('status','Status'),$this->f('operations','Operations')]],
            'locations'=>['Locations',[$this->f('location','Location'),$this->f('warehouse','Warehouse'),$this->f('parent','Parent'),$this->f('type','Type'),$this->f('coordinates','Coordinates'),$this->f('receiving','Receiving'),$this->f('picking','Picking'),$this->f('priority','Priority','integer'),$this->f('status','Status')]],
            'stock'=>['Inventory_Stock',[$this->f('product','Product'),$this->f('sku','SKU'),$this->f('warehouse','Warehouse'),$this->f('location','Location'),$this->f('on_hand','On Hand','decimal'),$this->f('reserved','Reserved','decimal'),$this->f('available','Available','decimal'),$this->f('average_cost','Average Cost','decimal')]],
            'receipts'=>['Inventory_Receipts',[$this->f('receipt','Receipt'),$this->f('warehouse','Warehouse'),$this->f('vendor','Vendor'),$this->f('date','Date','date'),$this->f('quantity','Quantity','decimal'),$this->f('currency','Currency'),$this->f('value','Value','decimal'),$this->f('status','Status')]],
            'deliveries'=>['Sales_Deliveries',[$this->f('delivery','Delivery'),$this->f('order','Sales Order'),$this->f('customer','Customer'),$this->f('date','Date','date'),$this->f('status','Status')]],
            'returns'=>['Returns',[$this->f('document','Document'),$this->f('reference','Reference'),$this->f('date','Date','date'),$this->f('status','Status')]],
            'invoices'=>['Customer_Invoices',[$this->f('invoice','Invoice'),$this->f('customer','Customer'),$this->f('sales_order','Sales Order'),$this->f('date','Date','date'),$this->f('due','Due','date'),$this->f('total','Total','decimal'),$this->f('residual','Residual','decimal'),$this->f('state','State'),$this->f('payment','Payment')]],
            'credit-notes'=>['Credit_Notes',[$this->f('reference','Reference'),$this->f('customer','Customer'),$this->f('date','Date','date'),$this->f('currency','Currency'),$this->f('total','Total','decimal'),$this->f('status','Status')]],
            'finance-journals'=>['Finance_Journals',[$this->f('batch','Batch'),$this->f('source','Source'),$this->f('description','Description'),$this->f('posting_date','Posting Date','date'),$this->f('debit','Debit','decimal'),$this->f('credit','Credit','decimal'),$this->f('status','Status')]],
            'expenses'=>['Finance_Expenses',[$this->f('request','Request'),$this->f('requester','Requester'),$this->f('category','Category'),$this->f('expense_date','Expense Date','date'),$this->f('currency','Currency'),$this->f('amount','Amount','decimal'),$this->f('status','Status'),$this->f('submitted','Submitted','date')]],
            'purchase-orders'=>['Purchase_Orders',[$this->f('po','PO'),$this->f('supplier','Supplier'),$this->f('date','Date','date'),$this->f('expected','Expected','date'),$this->f('status','Status'),$this->f('received','Received','decimal'),$this->f('billed','Billed','decimal'),$this->f('currency','Currency'),$this->f('total','Total','decimal')]],
        ];
        [$filename,$fields] = $definitions[$entity] ?? throw new InvalidArgumentException('No section-specific export definition exists.');
        return ['filename'=>$filename,'fields'=>$fields];
    }

    private function f(string $key,string $label,string $type='string'): ExchangeField
    {
        return new ExchangeField($key,$label,false,$type,[],null,false,true);
    }
}
