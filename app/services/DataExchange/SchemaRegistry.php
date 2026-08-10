<?php

declare(strict_types=1);

namespace App\Services\DataExchange;

use InvalidArgumentException;

final class SchemaRegistry
{
    /** @return array<string, ExchangeSchema> */
    public function all(): array
    {
        $external = new ExchangeField('external_id', 'External ID', false, 'string', ['xml id'], 'customer_acme');
        $schemas = [
            $this->schema('customers', 'Customers', 'sales', [$external, $this->f('customer_number','Customer Code',true), $this->f('name','Name',true), $this->f('email','Email',false,'email'), $this->f('phone','Phone'), $this->f('mobile','Mobile'), $this->f('street','Street'), $this->f('street2','Street 2'), $this->f('city','City'), $this->f('state_region','State/Region'), $this->f('postal_code','Postal Code'), $this->f('country','Country'), $this->f('credit_limit','Credit Limit',false,'decimal'), $this->f('payment_terms_days','Payment Terms (Days)',false,'integer'), $this->f('preferred_currency','Currency'), $this->f('active','Active')]),
            $this->schema('products', 'Products', 'sales', [$external, $this->f('sku','SKU',true), $this->f('name','Name',true), $this->f('description','Description'), $this->f('category','Category'), $this->f('unit_of_measure','UoM'), $this->f('unit_price','Sale Price',false,'decimal'), $this->f('cost','Cost',false,'decimal'), $this->f('product_type','Product Type'), $this->f('serial_tracking','Serial Tracking'), $this->f('active','Active')]),
            $this->schema('pricelists', 'Pricelists', 'sales', [$external, $this->f('name','Name',true), $this->f('currency','Currency',true), $this->f('product','Product'), $this->f('minimum_quantity','Minimum Quantity',false,'decimal'), $this->f('calculation','Calculation'), $this->f('fixed_price','Fixed Price',false,'decimal'), $this->f('percentage_adjustment','Percentage Adjustment',false,'decimal'), $this->f('valid_from','Valid From',false,'date'), $this->f('valid_to','Valid To',false,'date'), $this->f('priority','Priority',false,'integer')]),
            $this->schema('sales-teams', 'Sales Teams', 'sales', [$external, $this->f('name','Name',true), $this->f('leader','Leader'), $this->f('territory','Territory'), $this->f('members','Members')]),
            $this->schema('quotations', 'Quotations', 'sales', [$external, $this->f('customer','Customer',true), $this->f('salesperson','Salesperson'), $this->f('sales_team','Sales Team'), $this->f('pricelist','Pricelist'), $this->f('quotation_date','Quotation Date',false,'date'), $this->f('expiration_date','Expiration Date',false,'date'), $this->f('payment_terms_days','Payment Terms (Days)',false,'integer'), $this->f('currency','Currency'), $this->f('notes','Notes'), $this->f('line_external_id','Line External ID'), $this->f('product','Product',true), $this->f('description','Description'), $this->f('quantity','Quantity',true,'decimal'), $this->f('unit_of_measure','UoM'), $this->f('unit_price','Unit Price',false,'decimal'), $this->f('discount','Discount %',false,'decimal'), $this->f('tax','Tax')], true),
            $this->schema('sales-orders', 'Sales Orders', 'sales', [$external, $this->f('customer','Customer',true), $this->f('order_date','Order Date',false,'date'), $this->f('currency','Currency'), $this->f('product','Product',true), $this->f('quantity','Quantity',true,'decimal'), $this->f('unit_price','Unit Price',false,'decimal'), $this->f('discount','Discount %',false,'decimal')], true),
            $this->schema('warehouses', 'Warehouses', 'inventory', [$external, $this->f('code','Code',true), $this->f('name','Name',true), $this->f('branch','Branch'), $this->f('timezone','Timezone'), $this->f('is_default','Default'), $this->f('active','Active')]),
            $this->schema('locations', 'Locations', 'inventory', [$external, $this->f('warehouse','Warehouse',true), $this->f('code','Code',true), $this->f('name','Name',true), $this->f('usage','Usage',true), $this->f('parent','Parent Location'), $this->f('barcode','Barcode'), $this->f('active','Active')]),
            $this->readonly('stock', 'Stock', 'inventory', [$external, $this->f('warehouse','Warehouse'), $this->f('location','Location'), $this->f('sku','SKU'), $this->f('product','Product'), $this->f('on_hand','On Hand',false,'decimal'), $this->f('reserved','Reserved',false,'decimal'), $this->f('available','Available',false,'decimal')]),
            $this->schema('physical-counts', 'Physical Counts', 'inventory', [$external, $this->f('warehouse','Warehouse',true), $this->f('location','Location',true), $this->f('product','Product',true), $this->f('lot_serial','Lot/Serial'), $this->f('counted_quantity','Counted Quantity',true,'decimal'), $this->f('count_date','Count Date',true,'date')]),
            $this->document('receipts','Receipts','inventory'), $this->document('deliveries','Deliveries','inventory'), $this->document('transfers','Internal Transfers','inventory'),
            $this->readonly('returns', 'Returns', 'inventory', [$external, $this->f('document_number','Document Number'), $this->f('product','Product'), $this->f('quantity','Quantity',false,'decimal'), $this->f('status','Status')]),
            $this->schema('lots-serials', 'Lots/Serials', 'inventory', [$external, $this->f('product','Product',true), $this->f('lot_serial','Lot/Serial',true), $this->f('expiration_date','Expiration Date',false,'date'), $this->f('active','Active')]),
            $this->schema('chart-of-accounts', 'Chart of Accounts', 'finance', [$external, $this->f('code','Account Code',true), $this->f('name','Account Name',true), $this->f('type','Account Type',true), $this->f('currency','Currency'), $this->f('reconcile','Allow Reconciliation'), $this->f('active','Active')]),
            $this->schema('journals', 'Journals', 'finance', [$external, $this->f('code','Journal Code',true), $this->f('name','Journal Name',true), $this->f('type','Journal Type',true), $this->f('currency','Currency'), $this->f('active','Active')]),
            $this->schema('journal-entries', 'Journal Entries', 'finance', [$external, $this->f('journal','Journal',true), $this->f('date','Date',true,'date'), $this->f('reference','Reference'), $this->f('line_external_id','Line External ID'), $this->f('account','Account',true), $this->f('partner','Partner'), $this->f('label','Label'), $this->f('debit','Debit',false,'decimal'), $this->f('credit','Credit',false,'decimal'), $this->f('currency','Currency')], true),
            $this->financeDocument('invoices','Customer Invoices'), $this->financeDocument('credit-notes','Credit Notes'),
            $this->schema('payments', 'Payments', 'finance', [$external, $this->f('date','Date',true,'date'), $this->f('partner','Partner',true), $this->f('journal','Journal',true), $this->f('amount','Amount',true,'decimal'), $this->f('currency','Currency'), $this->f('reference','Reference')]),
            $this->schema('bank-transactions', 'Bank Transactions', 'finance', [$external, $this->f('date','Date',true,'date'), $this->f('journal','Bank Journal',true), $this->f('reference','Reference',true), $this->f('partner','Partner'), $this->f('amount','Amount',true,'decimal'), $this->f('currency','Currency')]),
        ];
        foreach (['general-ledger'=>'General Ledger','trial-balance'=>'Trial Balance','profit-loss'=>'Profit & Loss','balance-sheet'=>'Balance Sheet','cash-flow'=>'Cash Flow','aged-receivable'=>'Aged Receivable','aged-payable'=>'Aged Payable','partner-ledger'=>'Partner Ledger','customer-statement'=>'Customer Statement','budget-report'=>'Budget Report'] as $entity => $label) {
            $schemas[] = $this->readonly($entity, $label, 'finance', [$this->f('date','Date'),$this->f('account','Account'),$this->f('partner','Partner'),$this->f('reference','Reference'),$this->f('debit','Debit',false,'decimal'),$this->f('credit','Credit',false,'decimal'),$this->f('balance','Balance',false,'decimal')]);
        }
        $result = [];
        foreach ($schemas as $schema) {
            // Import is advertised only when final persistence is connected
            // to an existing domain service. Other registered datasets remain
            // explicit export-only objects instead of presenting a dead end.
            $connectedExports = [
                'customers', 'products', 'pricelists', 'sales-teams',
                'quotations', 'sales-orders', 'warehouses', 'locations',
                'stock', 'receipts', 'deliveries', 'returns', 'invoices',
                'credit-notes',
            ];
            if (($schema->canImport && !in_array($schema->entity, [
                'customers',
                'products',
                'quotations',
            ], true)) || ($schema->canExport && !in_array($schema->entity, $connectedExports, true))) {
                $schema = new ExchangeSchema(
                    $schema->entity,
                    $schema->label,
                    $schema->module,
                    $schema->fields,
                    false,
                    in_array($schema->entity, $connectedExports, true),
                    $schema->groupByExternalId
                );
            }
            $result[$schema->entity] = $schema;
        }
        return $result;
    }

    public function get(string $entity): ExchangeSchema
    {
        return $this->all()[$entity] ?? throw new InvalidArgumentException('Unsupported import/export object.');
    }

    private function f(string $key,string $label,bool $required=false,string $type='string'): ExchangeField{return new ExchangeField($key,$label,$required,$type);}
    /** @param list<ExchangeField> $fields */
    private function schema(string $entity,string $label,string $module,array $fields,bool $group=false,bool $export=true): ExchangeSchema{return new ExchangeSchema($entity,$label,$module,$fields,true,$export,$group);}
    /** @param list<ExchangeField> $fields */
    private function readonly(string $entity,string $label,string $module,array $fields): ExchangeSchema{return new ExchangeSchema($entity,$label,$module,$fields,false,true,false);}
    private function document(string $entity,string $label,string $module): ExchangeSchema{return $this->schema($entity,$label,$module,[new ExchangeField('external_id','External ID'),$this->f('date','Date',true,'date'),$this->f('warehouse','Warehouse',true),$this->f('source_location','Source Location'),$this->f('destination_location','Destination Location'),$this->f('partner','Partner'),$this->f('product','Product',true),$this->f('quantity','Quantity',true,'decimal'),$this->f('lot_serial','Lot/Serial'),$this->f('reference','Reference')],true);}
    private function financeDocument(string $entity,string $label): ExchangeSchema{return $this->schema($entity,$label,'finance',[new ExchangeField('external_id','External ID'),$this->f('partner','Partner',true),$this->f('invoice_date','Invoice Date',true,'date'),$this->f('due_date','Due Date',false,'date'),$this->f('currency','Currency'),$this->f('reference','Reference'),$this->f('product','Product'),$this->f('description','Description'),$this->f('account','Account'),$this->f('quantity','Quantity',true,'decimal'),$this->f('unit_price','Unit Price',true,'decimal'),$this->f('tax','Tax')],true);}
}
