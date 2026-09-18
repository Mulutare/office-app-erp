<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

final class FinanceExpenseEvidenceService
{
    private function company(): int { return (new TenantContext())->companyId(); }

    public function prevalidate(array $input, ?int $expenseId = null, int $actor = 0): void
    {
        $files=$this->files($input);
        if ($files === []) return;
        foreach ($files as $file) (new PrivateUploadService())->validateExpenseEvidence($file);
        if ($expenseId === null) return;
        $company=$this->company();
        $s=\db()->prepare('SELECT r.status,r.created_by,(SELECT COUNT(*) FROM finance_expense_evidence e WHERE e.company_id=r.company_id AND e.expense_request_id=r.expense_request_id) evidence_count FROM finance_expense_requests r WHERE r.company_id=:company AND r.expense_request_id=:expense AND r.deleted_at IS NULL');
        $s->execute(['company'=>$company,'expense'=>$expenseId]); $row=$s->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['status']!=='draft' || (int)$row['created_by']!==$actor) throw new RuntimeException('Only the creator may change evidence on an editable draft.');
        if ((int)$row['evidence_count']+count($files)>10) throw new RuntimeException('An expense may have no more than 10 evidence files.');
    }

    public function list(int $expenseId): array
    {
        $s = \db()->prepare('SELECT e.evidence_id,e.sequence,e.original_name,e.file_size,e.created_at FROM finance_expense_evidence e JOIN finance_expense_requests r ON r.company_id=e.company_id AND r.expense_request_id=e.expense_request_id WHERE e.company_id=:company AND e.expense_request_id=:expense AND r.deleted_at IS NULL ORDER BY e.sequence');
        $s->execute(['company'=>$this->company(),'expense'=>$expenseId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listMany(array $expenseIds): array
    {
        if ($expenseIds === []) return [];
        $placeholders=implode(',',array_fill(0,count($expenseIds),'?'));
        $s=\db()->prepare("SELECT e.evidence_id,e.expense_request_id,e.sequence,e.original_name,e.file_size,e.created_at FROM finance_expense_evidence e JOIN finance_expense_requests r ON r.company_id=e.company_id AND r.expense_request_id=e.expense_request_id WHERE e.company_id=? AND r.deleted_at IS NULL AND e.expense_request_id IN ($placeholders) ORDER BY e.expense_request_id,e.sequence");
        $s->execute(array_merge([$this->company()],array_map('intval',$expenseIds)));
        $grouped=[];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) $grouped[(int)$row['expense_request_id']][]=$row;
        return $grouped;
    }

    public function upload(int $expenseId, array $input, int $actor): void
    {
        $this->prevalidate($input,$expenseId,$actor);
        $files = $this->files($input);
        if ($files === []) return;
        $company = $this->company();
        $db = \db();
        $upload = new PrivateUploadService();
        $stored = [];
        $db->beginTransaction();
        try {
            $this->draft($db,$company,$expenseId,$actor);
            $s = $db->prepare('SELECT sequence FROM finance_expense_evidence WHERE company_id=:company AND expense_request_id=:expense ORDER BY sequence FOR UPDATE');
            $s->execute(['company'=>$company,'expense'=>$expenseId]);
            $used = array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));
            if (count($used)+count($files)>10) throw new RuntimeException('An expense may have no more than 10 evidence files.');
            $insert = $db->prepare('INSERT INTO finance_expense_evidence(company_id,expense_request_id,sequence,original_name,storage_path,mime_type,file_size,sha256,uploaded_by_user_id) VALUES(:company,:expense,:sequence,:name,:path,:mime,:size,:sha,:actor)');
            foreach ($files as $file) {
                for ($sequence=1; in_array($sequence,$used,true); $sequence++) {}
                $used[] = $sequence;
                $item = $upload->storeExpenseEvidence($company,$file);
                $stored[] = $item['evidence_path'];
                $insert->execute(['company'=>$company,'expense'=>$expenseId,'sequence'=>$sequence,'name'=>$item['evidence_original_name'],'path'=>$item['evidence_path'],'mime'=>$item['evidence_mime'],'size'=>$item['evidence_size'],'sha'=>$item['evidence_sha256'],'actor'=>$actor]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            foreach ($stored as $path) $this->removeStored($company,$expenseId,0,$path);
            throw $e;
        }
    }

    public function remove(int $expenseId, int $evidenceId, int $actor): void
    {
        $company=$this->company(); $db=\db(); $db->beginTransaction();
        try {
            $this->draft($db,$company,$expenseId,$actor);
            $s=$db->prepare('SELECT storage_path FROM finance_expense_evidence WHERE company_id=:company AND expense_request_id=:expense AND evidence_id=:evidence FOR UPDATE');
            $s->execute(['company'=>$company,'expense'=>$expenseId,'evidence'=>$evidenceId]);
            $path=$s->fetchColumn();
            if (!is_string($path)) throw new RuntimeException('Expense evidence was not found.');
            $db->prepare('DELETE FROM finance_expense_evidence WHERE company_id=:company AND expense_request_id=:expense AND evidence_id=:evidence')->execute(['company'=>$company,'expense'=>$expenseId,'evidence'=>$evidenceId]);
            $db->commit();
            $this->removeStored($company,$expenseId,$evidenceId,$path);
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    }

    public function download(int $expenseId, int $evidenceId): ?array
    {
        $company=$this->company();
        $s=\db()->prepare('SELECT e.storage_path,e.original_name,e.mime_type,e.file_size FROM finance_expense_evidence e JOIN finance_expense_requests r ON r.company_id=e.company_id AND r.expense_request_id=e.expense_request_id WHERE e.company_id=:company AND e.expense_request_id=:expense AND e.evidence_id=:evidence AND r.deleted_at IS NULL');
        $s->execute(['company'=>$company,'expense'=>$expenseId,'evidence'=>$evidenceId]);
        $row=$s->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        $root=realpath(dirname(__DIR__,2).'/storage/private/expense-evidence/company-'.$company);
        $path=realpath((string)$row['storage_path']);
        if ($root===false || $path===false || !str_starts_with($path,$root.DIRECTORY_SEPARATOR) || !is_file($path)
            || !in_array($row['mime_type'],['application/pdf','image/png','image/jpeg'],true)) return null;
        $row['storage_path']=$path;
        return $row;
    }

    private function draft(PDO $db,int $company,int $expenseId,int $actor): array
    {
        $s=$db->prepare('SELECT status,created_by FROM finance_expense_requests WHERE company_id=:company AND expense_request_id=:expense AND deleted_at IS NULL FOR UPDATE');
        $s->execute(['company'=>$company,'expense'=>$expenseId]); $row=$s->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['status']!=='draft' || (int)$row['created_by']!==$actor) throw new RuntimeException('Only the creator may change evidence on an editable draft.');
        return $row;
    }

    private function files(array $input): array
    {
        if ($input === []) return [];
        if (!isset($input['name']) || !is_array($input['name'])) throw new RuntimeException('Invalid expense evidence upload batch.');
        $files=[];
        foreach ($input['name'] as $i=>$name) {
            $error=(int)($input['error'][$i]??UPLOAD_ERR_NO_FILE);
            if ($error===UPLOAD_ERR_NO_FILE) {
                if ((string)$name!=='' || (string)($input['tmp_name'][$i]??'')!=='' || (int)($input['size'][$i]??0)!==0) throw new RuntimeException('Invalid empty expense evidence entry.');
                continue;
            }
            $files[]=['name'=>$name,'type'=>$input['type'][$i]??'', 'tmp_name'=>$input['tmp_name'][$i]??'', 'error'=>$error,'size'=>$input['size'][$i]??0];
        }
        if (count($files)>10) throw new RuntimeException('An expense may have no more than 10 evidence files.');
        return $files;
    }

    private function removeStored(int $company,int $expenseId,int $evidenceId,string $path): void
    {
        $root=realpath(dirname(__DIR__,2).'/storage/private/expense-evidence/company-'.$company);
        $resolved=realpath($path);
        if ($resolved===false || $root===false || !str_starts_with($resolved,$root.DIRECTORY_SEPARATOR) || !is_file($resolved) || !@unlink($resolved)) {
            error_log(sprintf('Expense evidence orphan cleanup required: company_id=%d expense_request_id=%d evidence_id=%d', $company,$expenseId,$evidenceId));
        }
    }
}
