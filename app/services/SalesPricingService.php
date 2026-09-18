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
        $mayReview=!(new SalesHierarchyScope())->isAgent($company,$actorId)
            && ((new ModuleRoleService())->permissionAllowed($company,$actorId,'sales.pricing.manage')
            || (new ModuleRoleService())->permissionAllowed($company,$actorId,'sales.pricing.approve'));
        $changes=\db()->prepare('SELECT c.*,p.sku,p.name product_name FROM sales_product_price_changes c JOIN sales_products p ON p.company_id=c.company_id AND p.product_id=c.product_id WHERE c.company_id=?'
            .($mayReview?'':" AND c.status='approved'").' ORDER BY c.price_change_id DESC LIMIT 300');
        $changes->execute([$company]);
        $products=\db()->prepare('SELECT product_id,sku,name,unit_price FROM sales_products WHERE company_id=? AND active=TRUE AND deleted_at IS NULL ORDER BY name');
        $products->execute([$company]);
        return ['changes'=>$changes->fetchAll(PDO::FETCH_ASSOC),'products'=>$products->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function submit(array $input, int $actorId): int
    {
        $company=(new TenantContext())->companyId();$this->permit($company,$actorId,'manage');
        $productId=(int)($input['product_id']??0);
        $price=$this->money($input['proposed_price']??null);
        $discount=$this->money($input['approved_discount_per_unit']??0);
        $reason=trim((string)($input['reason']??''));
        $dateText=trim((string)($input['effective_from']??''));
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$dateText);
        if ($price<=0 || $discount<0 || $discount>=$price) throw new RuntimeException('The discount must be below the positive unit price.');
        if ($reason==='' || strlen($reason)>1000) throw new RuntimeException('Enter a price-change reason of up to 1000 characters.');
        if (!$date || $date->format('Y-m-d')!==$dateText) throw new RuntimeException('Enter a valid effective date.');
        if($dateText<date('Y-m-d'))throw new RuntimeException('A new approved price cannot be backdated.');
        $connection=\db();$connection->beginTransaction();
        try {
            $product=$connection->prepare('SELECT product_id FROM sales_products WHERE company_id=? AND product_id=? AND active=TRUE AND deleted_at IS NULL FOR UPDATE');
            $product->execute([$company,$productId]);
            if ($product->fetchColumn()===false) throw new RuntimeException('Select an active company SKU.');
            $currency=$connection->prepare('SELECT default_currency FROM companies WHERE company_id=?');
            $currency->execute([$company]);$code=strtoupper((string)$currency->fetchColumn());
            if (preg_match('/^[A-Z]{3}$/',$code)!==1) throw new RuntimeException('Company currency is not configured.');
            $old=$this->effective($company,$productId,$dateText,$code);
            $insert=$connection->prepare("INSERT INTO sales_product_price_changes(company_id,product_id,old_price,proposed_price,approved_discount_per_unit,currency,effective_from,reason,status,requested_by,requested_at) VALUES(?,?,?,?,?,?,?,?,'submitted',?,NOW())");
            $insert->execute([$company,$productId,$old['unit_price'],$price,$discount,$code,$dateText.' 00:00:00',$reason,$actorId]);
            $id=(int)$connection->lastInsertId();
            $users=$connection->prepare('SELECT cu.user_id FROM company_users cu JOIN users u ON u.user_id=cu.user_id WHERE cu.company_id=? AND cu.active=TRUE AND u.active=TRUE AND u.deleted_at IS NULL AND cu.user_id<>?');
            $users->execute([$company,$actorId]);
            $permission=new ModuleRoleService();$notifications=new UserNotificationService($connection);
            foreach($users->fetchAll(PDO::FETCH_ASSOC) as $user){$checker=(int)$user['user_id'];if(!$permission->permissionAllowed($company,$checker,'sales.pricing.approve'))continue;$notifications->notify($company,$checker,'sales.pricing.review','Price approval required','Review the submitted SKU price and exact discount.','sales_product_price_change',$id,'/sales/pricing','sales-pricing:'.$id.':review:'.$checker);}
            $connection->commit();return $id;
        } catch (\Throwable $e) { if ($connection->inTransaction()) $connection->rollBack();throw $e; }
    }

    public function decide(int $id, bool $approve, string $reason, int $actorId): void
    {
        $company=(new TenantContext())->companyId();$this->permit($company,$actorId,'approve');
        $reason=trim($reason);
        if ((!$approve && $reason==='') || strlen($reason)>1000) throw new RuntimeException('Enter a decision reason of up to 1000 characters.');
        $connection=\db();$connection->beginTransaction();
        try {
            $query=$connection->prepare('SELECT * FROM sales_product_price_changes WHERE company_id=? AND price_change_id=? FOR UPDATE');
            $query->execute([$company,$id]);$row=$query->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['status']!=='submitted') throw new RuntimeException('The price request is not pending.');
            if ((int)$row['requested_by']===$actorId) throw new RuntimeException('Maker and checker must differ.');
            if ($approve) {
                if(substr((string)$row['effective_from'],0,10)<date('Y-m-d'))throw new RuntimeException('The requested effective date has passed; submit a new future-dated price request.');
                $product=$connection->prepare('SELECT product_id FROM sales_products WHERE company_id=? AND product_id=? AND active=TRUE AND deleted_at IS NULL FOR UPDATE');
                $product->execute([$company,$row['product_id']]);
                if (!$product->fetchColumn()) throw new RuntimeException('The SKU is no longer active.');
                $connection->prepare("UPDATE sales_product_price_changes SET status='approved',approved_by=?,approved_at=NOW(),decision_reason=? WHERE company_id=? AND price_change_id=?")->execute([$actorId,$reason?:null,$company,$id]);
            } else {
                $connection->prepare("UPDATE sales_product_price_changes SET status='rejected',rejected_by=?,rejected_at=NOW(),decision_reason=? WHERE company_id=? AND price_change_id=?")->execute([$actorId,$reason,$company,$id]);
            }
            (new UserNotificationService($connection))->notify($company,(int)$row['requested_by'],$approve?'sales.pricing.approved':'sales.pricing.rejected',$approve?'Price change approved':'Price change rejected',$approve?'Approved price and discount will apply from the effective date.':$reason,'sales_product_price_change',$id,'/sales/pricing','sales-pricing:'.$id.':'.($approve?'approved':'rejected'));
            $connection->commit();
        } catch (\Throwable $e) { if ($connection->inTransaction()) $connection->rollBack();throw $e; }
    }

    private function permit(int $company, int $actor, string $suffix): void
    {
        if (!(new ModuleRoleService())->permissionAllowed($company,$actor,'sales.pricing.'.$suffix)
            || (new SalesHierarchyScope())->isAgent($company,$actor)) {
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
