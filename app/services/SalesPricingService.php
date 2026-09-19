<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\RepositoryFactory;
use PDO;
use RuntimeException;

final class SalesPricingService
{
    public function effective(int $companyId, int $productId, string $at, string $currency): array
    {
        return RepositoryFactory::sales()->resolvePrice($companyId,$productId,$at,$currency);
    }

    public function register(int $actorId): array
    {
        $company=(new TenantContext())->companyId();$this->permit($company,$actorId,'view');
        $canManage=!(new SalesHierarchyScope())->isAgent($company,$actorId)
            && (new ModuleRoleService())->permissionAllowed($company,$actorId,'sales.pricing.manage');
        $changes=\db()->prepare('SELECT c.*,p.sku,p.name product_name,u.display_name changed_by_name FROM sales_product_price_changes c JOIN sales_products p ON p.company_id=c.company_id AND p.product_id=c.product_id LEFT JOIN users u ON u.user_id=c.requested_by WHERE c.company_id=?'
            .($canManage?'':" AND c.status='approved'").' ORDER BY c.price_change_id DESC LIMIT 300');
        $changes->execute([$company]);
        $products=\db()->prepare('SELECT p.product_id,p.sku,p.name,p.product_type,p.active,m.model_name,m.product_family,m.mifi_subtype,b.name brand_name FROM sales_products p LEFT JOIN sales_product_models m ON m.company_id=p.company_id AND m.model_id=p.model_id LEFT JOIN sales_product_brands b ON b.company_id=m.company_id AND b.brand_id=m.brand_id WHERE p.company_id=? AND p.deleted_at IS NULL ORDER BY p.active DESC,p.sku');
        $products->execute([$company]);
        $productRows=$products->fetchAll(PDO::FETCH_ASSOC);
        $currency=\db()->prepare('SELECT default_currency FROM companies WHERE company_id=?');
        $currency->execute([$company]);
        $code=(string)$currency->fetchColumn();
        $now=date('Y-m-d H:i:s');
        foreach($productRows as &$product){
            $approved=$this->effective($company,(int)$product['product_id'],$now,$code);
            $product['approved_price']=$approved['unit_price'];
            $product['approved_discount_percent']=$approved['discount_percent'];
            $product['approved_tax_percent']=$approved['tax_percent'];
            $product['effective_from']=$approved['effective_from']??null;
            $product['updated_at']=$approved['requested_at']??null;
            $product['updated_by']=$approved['changed_by_name']??null;
        }
        unset($product);
        return ['changes'=>$changes->fetchAll(PDO::FETCH_ASSOC),'products'=>$productRows];
    }

    public function submit(array $input, int $actorId): int
    {
        $company=(new TenantContext())->companyId();$this->permit($company,$actorId,'manage');
        $productId=(int)($input['product_id']??0);
        $price=$this->money($input['proposed_price']??null);
        $discountPercent=$this->money($input['approved_discount_percent']??null);
        $taxPercent=$this->money($input['approved_tax_percent']??null);
        $discount=round($price*$discountPercent/100,2);
        $reason=trim((string)($input['reason']??''));
        $dateText=date('Y-m-d');
        if ($price<=0 || $discountPercent<0 || $discountPercent>=100 || $discount>=$price || $taxPercent<0 || $taxPercent>100) throw new RuntimeException('Enter a positive unit price, discount below 100%, and tax from 0% to 100%.');
        if (strlen($reason)>1000) throw new RuntimeException('Enter a price-change reason of up to 1000 characters.');
        $connection=\db();$connection->beginTransaction();
        try {
            $product=$connection->prepare('SELECT product_id FROM sales_products WHERE company_id=? AND product_id=? AND active=TRUE AND deleted_at IS NULL FOR UPDATE');
            $product->execute([$company,$productId]);
            if ($product->fetchColumn()===false) throw new RuntimeException('Select an active company SKU.');
            $currency=$connection->prepare('SELECT default_currency FROM companies WHERE company_id=?');
            $currency->execute([$company]);$code=strtoupper((string)$currency->fetchColumn());
            if (preg_match('/^[A-Z]{3}$/',$code)!==1) throw new RuntimeException('Company currency is not configured.');
            $old=$this->effective($company,$productId,$dateText,$code);
            $connection->prepare("UPDATE sales_product_price_changes SET status='rejected',rejected_by=?,rejected_at=NOW(),decision_reason='Superseded by direct pricing update' WHERE company_id=? AND product_id=? AND status='approved' AND effective_from>NOW()")
                ->execute([$actorId,$company,$productId]);
            $insert=$connection->prepare("INSERT INTO sales_product_price_changes(company_id,product_id,old_price,old_discount_percent,old_tax_percent,proposed_price,approved_discount_per_unit,approved_discount_percent,approved_tax_percent,currency,effective_from,reason,status,requested_by,requested_at,approved_by,approved_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,'approved',?,NOW(),?,NOW())");
            $insert->execute([$company,$productId,$old['unit_price'],$old['discount_percent'],$old['tax_percent'],$price,$discount,$discountPercent,$taxPercent,$code,$dateText.' 00:00:00',$reason,$actorId,$actorId]);
            $id=(int)$connection->lastInsertId();
            $connection->prepare('UPDATE sales_products SET unit_price=? WHERE company_id=? AND product_id=?')
                ->execute([$price,$company,$productId]);
            $connection->commit();return $id;
        } catch (\Throwable $e) { if ($connection->inTransaction()) $connection->rollBack();throw $e; }
    }

    private function permit(int $company, int $actor, string $suffix): void
    {
        if (!(new ModuleRoleService())->permissionAllowed($company,$actor,'sales.pricing.'.$suffix)
            || ($suffix !== 'view' && (new SalesHierarchyScope())->isAgent($company,$actor))) {
            throw new RuntimeException('Dedicated Sales pricing permission is required.');
        }
    }

    private function money(mixed $value): float
    {
        if (!is_scalar($value) || !is_numeric($value)) throw new RuntimeException('Enter a valid amount.');
        $number=(float)$value;
        if (!is_finite($number) || abs($number)>9999999999999.99 || round($number,2)!==$number) throw new RuntimeException('Enter an amount with at most two decimals.');
        return $number;
    }
}
