<?php
declare(strict_types=1);
return [
    'version'=>'044','description'=>'Represent unreserved delivery pickings as waiting for stock',
    'preflight'=>static function(PDO $c):string{
        $s=$c->query("SELECT CHECK_CLAUSE FROM information_schema.check_constraints WHERE constraint_schema=DATABASE() AND constraint_name='ck_inventory_picking_status' LIMIT 1");
        $clause=(string)$s->fetchColumn();if($clause==='')throw new RuntimeException('Migration 044 could not find the Inventory picking status constraint.');
        return str_contains($clause,'waiting_stock')?'baseline':'apply';
    },
    'statements'=>[
        "ALTER TABLE inventory_pickings DROP CONSTRAINT ck_inventory_picking_status",
        "ALTER TABLE inventory_pickings ADD CONSTRAINT ck_inventory_picking_status CHECK(status IN('draft','waiting_stock','ready','partially_done','done','cancelled'))",
        "UPDATE inventory_pickings p SET p.status='waiting_stock' WHERE p.status='ready' AND EXISTS(SELECT 1 FROM inventory_picking_lines l WHERE l.company_id=p.company_id AND l.picking_id=p.picking_id) AND NOT EXISTS(SELECT 1 FROM inventory_picking_lines l WHERE l.company_id=p.company_id AND l.picking_id=p.picking_id AND l.reserved_quantity>l.completed_quantity)"
    ]
];
