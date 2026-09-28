<?php

declare(strict_types=1);

namespace App\Services\Lists;

use App\Services\InventoryOperationalAccessService;
use App\Services\TenantContext;
use PDO;

final class ProcurementWorkspaceListService
{
    public function workspace(array $input): array
    {
        $company=(new TenantContext())->companyId();
        $actor=(int)($_SESSION['auth']['user_id']??0);
        $section=ListQuery::text($input['section']??'overview');

        $allowed=[
            'overview','requisitions','orders','suppliers',
            'bills','receipts','payments','returns'
        ];
        if(!in_array($section,$allowed,true))$section='overview';

        $factory=new ProcurementListService();
        $access=new InventoryOperationalAccessService();

        $data=[
            'suppliers'=>[],
            'requisitions'=>[],
            'orders'=>[],
            'bills'=>[],
            'returns'=>[],
            'products'=>[],
            'warehouses'=>[],
            'receiving_locations'=>[],
            'payment_journals'=>[],
            'departments'=>[],
            'supplier_options'=>[],
            'approved_requisitions'=>[],
            'lists'=>[],
            'listControls'=>[],
            'exportLists'=>[],
            'summary'=>[],
            'canExport'=>true,
        ];

        $map=[
            'suppliers'=>['suppliers','suppliers'],
            'requisitions'=>['requisitions','requisitions'],
            'orders'=>['orders','purchase-orders'],
            'bills'=>['bills','bills'],
            'payments'=>['bills','bills'],
            'returns'=>['returns','returns'],
        ];

        if(isset($map[$section])){
            [$key,$entity]=$map[$section];

            $list=$factory->listing(
                $entity,
                $input,
                $key
            );

            $page=$list->page();

            if($entity==='requisitions'){
                $page['rows']=$factory->hydrateRequisitions(
                    $page['rows']
                );
            }

            $data[$key]=$page['rows'];
            $data['lists'][$key]=$page;
            $data['listControls'][$key]=
                $factory->controls($entity);
            $data['exportLists'][$key]=$list;
        }

        if($section==='overview'){
            $data['summary']=$factory->summary();
        }

        if($section==='requisitions'){
            $data['warehouses']=
                $access->warehousesForUser($company,$actor);

            $q=\db()->prepare(
                'SELECT product_id,sku,name,unit_of_measure,product_type
                 FROM sales_products
                 WHERE company_id=?
                   AND active=TRUE
                   AND deleted_at IS NULL
                 ORDER BY name,product_id'
            );
            $q->execute([$company]);
            $data['products']=$q->fetchAll(PDO::FETCH_ASSOC);

            $q=\db()->prepare(
                'SELECT
                    department_id,
                    code AS department_code,
                    name AS department_name
                 FROM hr_departments
                 WHERE company_id=?
                   AND active=TRUE
                   AND deleted_at IS NULL
                 ORDER BY name,department_id'
            );
            $q->execute([$company]);
            $data['departments']=$q->fetchAll(PDO::FETCH_ASSOC);
        }

        if($section==='orders'){
            $data['supplier_options']=
                $factory->supplierOptions();

            $data['approved_requisitions']=
                $factory->approvedRequisitionOptions();

            $data['warehouses']=
                $access->warehousesForUser($company,$actor);

            $data['receiving_locations']=
                $access->receivingLocationsForUser(
                    $company,
                    $actor
                );
        }

        if($section==='payments'){
            $q=\db()->prepare(
                "SELECT journal_id,journal_name,journal_type
                 FROM finance_journals
                 WHERE company_id=?
                   AND journal_type IN('bank','cash')
                   AND active=TRUE
                 ORDER BY journal_type,journal_name,journal_id"
            );
            $q->execute([$company]);
            $data['payment_journals']=
                $q->fetchAll(PDO::FETCH_ASSOC);
        }

        return $data;
    }
}