<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/** Shared read/routing relationships. This service never grants stock-operation access. */
final class StockHierarchy
{
    public function authorities(int $company): array
    {
        $s=\db()->prepare('SELECT a.*,w.branch_id,w.parent_warehouse_id,w.name warehouse_name,u.display_name,
            cu.manager_user_id FROM inventory_stock_authorities a
            JOIN inventory_warehouses w ON w.company_id=a.company_id AND w.warehouse_id=a.warehouse_id AND w.active=TRUE AND w.deleted_at IS NULL
            JOIN inventory_warehouse_locations l ON l.company_id=a.company_id AND l.warehouse_id=a.warehouse_id AND l.location_id=a.location_id AND l.active=TRUE AND l.deleted_at IS NULL
            JOIN company_users cu ON cu.company_id=a.company_id AND cu.user_id=a.user_id AND cu.active=TRUE
            JOIN users u ON u.user_id=cu.user_id AND u.active=TRUE AND u.deleted_at IS NULL
            WHERE a.company_id=? AND a.active=TRUE');
        $s->execute([$company]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function parent(array $child, array $authorities): ?array
    {
        $expected=['shop'=>'district','district'=>'regional'][$child['authority_level']]??null;
        if ($expected===null) return null;
        $candidates=array_values(array_filter($authorities, static fn($a)=>$a['authority_level']===$expected
            && (int)$child['branch_id']>0 && (int)$a['branch_id']===(int)$child['branch_id']));
        $explicit=(int)($child['parent_warehouse_id']??0);
        if ($explicit>0) {
            $matches=array_values(array_filter($candidates,static fn($a)=>(int)$a['warehouse_id']===$explicit));
            if (count($matches)!==1) throw new RuntimeException('The configured warehouse parent must have one active authority at the next level in this branch.');
            return $matches[0];
        }
        // A unique authority within an established branch is safe for legacy data.
        if (count($candidates)===1) return $candidates[0];
        $matches=array_values(array_filter($candidates,static fn($a)=>(int)$a['user_id']===(int)$child['manager_user_id']));
        return count($matches)===1 ? $matches[0] : null;
    }

    public function parentForUser(int $company,int $user): ?array
    {
        $all=$this->authorities($company);
        foreach ($all as $a) if ((int)$a['user_id']===$user) return $this->parent($a,$all);
        return null;
    }

    public function visibleAuthorities(int $company,int $actor): array
    {
        $all=$this->authorities($company); $visible=[];
        foreach ($all as $a) if ((int)$a['user_id']===$actor) $visible[(int)$a['authority_id']]=$a;
        do {
            $before=count($visible);
            foreach ($all as $a) {
                $p=$this->parent($a,$all);
                if ($p && isset($visible[(int)$p['authority_id']])) $visible[(int)$a['authority_id']]=$a;
            }
        } while (count($visible)>$before);
        return array_values($visible);
    }

    public function userIds(int $company,int $actor): array
    {
        $s=\db()->prepare('SELECT cu.user_id,cu.manager_user_id FROM company_users cu JOIN users u ON u.user_id=cu.user_id AND u.active=TRUE AND u.deleted_at IS NULL WHERE cu.company_id=? AND cu.active=TRUE');
        $s->execute([$company]); $parents=array_column($s->fetchAll(PDO::FETCH_ASSOC),'manager_user_id','user_id');
        if (!array_key_exists($actor,$parents)) return [];
        $all=$this->authorities($company); $authorityUsers=array_column($all,'authority_id','user_id');
        if (!isset($authorityUsers[$actor])) return [$actor];
        $visible=[$actor=>true];
        foreach ($this->visibleAuthorities($company,$actor) as $a) $visible[(int)$a['user_id']]=true;
        // Explicit stock hierarchy overrides conflicting legacy manager links.
        do {
            $before=count($visible);
            foreach ($parents as $user=>$parent) if (!isset($authorityUsers[$user]) && isset($visible[(int)$parent])) $visible[(int)$user]=true;
        } while (count($visible)>$before);
        return array_keys($visible);
    }

    public function saveParent(int $company,int $warehouse,int $parent,string $level): void
    {
        if ($parent===0) return; // Omitted input preserves the existing relationship.
        $s=\db()->prepare('SELECT warehouse_id,branch_id FROM inventory_warehouses WHERE company_id=? AND warehouse_id IN (?,?) AND active=TRUE AND deleted_at IS NULL FOR UPDATE');
        $s->execute([$company,$warehouse,$parent]); $rows=array_column($s->fetchAll(PDO::FETCH_ASSOC),null,'warehouse_id');
        $expected=['shop'=>'district','district'=>'regional'][$level]??null;
        if ($warehouse===$parent || !$expected || count($rows)!==2 || (int)$rows[$warehouse]['branch_id']<1 || $rows[$warehouse]['branch_id']!==$rows[$parent]['branch_id']) throw new RuntimeException('Choose a parent warehouse at the next level in the same branch.');
        $s=\db()->prepare('SELECT COUNT(*) FROM inventory_stock_authorities WHERE company_id=? AND warehouse_id=? AND authority_level=? AND active=TRUE');
        $s->execute([$company,$parent,$expected]);
        if ((int)$s->fetchColumn()!==1) throw new RuntimeException('The parent warehouse must have exactly one active authority at the next level.');
        $s=\db()->prepare("SELECT COUNT(*) FROM inventory_peer_proposals WHERE company_id=? AND (source_warehouse_id=? OR destination_warehouse_id=?) AND state IN('proposed','source_approved','dispatched')");
        $s->execute([$company,$warehouse,$warehouse]);
        if ((int)$s->fetchColumn()>0) throw new RuntimeException('Finish pending peer work before changing this warehouse parent.');
        $s=\db()->prepare('UPDATE inventory_warehouses SET parent_warehouse_id=? WHERE company_id=? AND warehouse_id=?');
        $s->execute([$parent,$company,$warehouse]);
    }
}
