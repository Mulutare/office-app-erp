<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class SalesProductVariantService
{
    private function company(): int { return (new TenantContext())->companyId(); }
    private function manage(int $company,int $actor): void
    {
        if (!(new ModuleRoleService())->permissionAllowed($company,$actor,'sales.catalogue.manage') || (new SalesHierarchyScope())->isAgent($company,$actor)) throw new RuntimeException('Product classification permission is required.');
    }

    public function options(): array
    {
        $company=$this->company();
        $brands=\db()->prepare('SELECT brand_id,name,active FROM sales_product_brands WHERE company_id=? ORDER BY name');$brands->execute([$company]);
        $models=\db()->prepare('SELECT m.model_id,m.brand_id,m.product_family,m.mifi_subtype,m.model_name,m.active,b.active brand_active,b.name brand_name FROM sales_product_models m JOIN sales_product_brands b ON b.company_id=m.company_id AND b.brand_id=m.brand_id WHERE m.company_id=? ORDER BY b.name,m.model_name');$models->execute([$company]);
        $products=\db()->prepare('SELECT p.product_id,p.sku,p.name,p.model_id,p.active,m.brand_id,m.product_family,m.mifi_subtype,m.model_name,b.name brand_name FROM sales_products p LEFT JOIN sales_product_models m ON m.company_id=p.company_id AND m.model_id=p.model_id LEFT JOIN sales_product_brands b ON b.company_id=m.company_id AND b.brand_id=m.brand_id WHERE p.company_id=? AND p.deleted_at IS NULL ORDER BY p.sku');$products->execute([$company]);
        return ['brands'=>$brands->fetchAll(PDO::FETCH_ASSOC),'models'=>$models->fetchAll(PDO::FETCH_ASSOC),'products'=>$products->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function createBrand(string $name,int $actor): int
    {
        $company=$this->company();$this->manage($company,$actor);$name=trim($name);
        if($name===''||strlen($name)>120)throw new RuntimeException('Enter a brand name of up to 120 characters.');
        $insert=\db()->prepare('INSERT INTO sales_product_brands(company_id,name,created_by) VALUES(?,?,?)');$insert->execute([$company,$name,$actor]);return (int)\db()->lastInsertId();
    }

    public function createModel(array $input,int $actor): int
    {
        $company=$this->company();$this->manage($company,$actor);
        $brandId=(int)($input['brand_id']??0);$family=(string)($input['product_family']??'');$subtype=trim((string)($input['mifi_subtype']??''))?:null;$name=trim((string)($input['model_name']??''));
        if(!in_array($family,['mobile','mifi'],true)||($family==='mobile'&&$subtype!==null)||($family==='mifi'&&!in_array($subtype,['portable','non_portable'],true))||$name===''||strlen($name)>160)throw new RuntimeException('Enter a valid Mobile or MiFi model and subtype.');
        $brand=\db()->prepare('SELECT brand_id FROM sales_product_brands WHERE company_id=? AND brand_id=? AND active=TRUE');$brand->execute([$company,$brandId]);
        if(!$brand->fetchColumn())throw new RuntimeException('Choose an active company brand.');
        $insert=\db()->prepare('INSERT INTO sales_product_models(company_id,brand_id,product_family,mifi_subtype,model_name,created_by) VALUES(?,?,?,?,?,?)');$insert->execute([$company,$brandId,$family,$subtype,$name,$actor]);return (int)\db()->lastInsertId();
    }

    public function assignModel(int $productId,?int $modelId,int $actor): void
    {
        $company=$this->company();$this->manage($company,$actor);
        $connection=\db();$connection->beginTransaction();
        try{
            $product=$connection->prepare("SELECT product_id FROM sales_products WHERE company_id=? AND product_id=? AND product_type IN('stockable','telecom_product') AND deleted_at IS NULL FOR UPDATE");$product->execute([$company,$productId]);
            if(!$product->fetchColumn())throw new RuntimeException('Company SKU not found.');
            if($modelId!==null){$model=$connection->prepare('SELECT m.model_id FROM sales_product_models m JOIN sales_product_brands b ON b.company_id=m.company_id AND b.brand_id=m.brand_id WHERE m.company_id=? AND m.model_id=? AND m.active=TRUE AND b.active=TRUE');$model->execute([$company,$modelId]);if(!$model->fetchColumn())throw new RuntimeException('Choose an active company brand and model.');}
            $connection->prepare('UPDATE sales_products SET model_id=? WHERE company_id=? AND product_id=?')->execute([$modelId,$company,$productId]);
            $connection->commit();
        }catch(\Throwable $e){if($connection->inTransaction())$connection->rollBack();throw $e;}
    }
}
