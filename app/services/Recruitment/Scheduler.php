<?php
declare(strict_types=1);
namespace App\Services\Recruitment;
final class Scheduler
{
    public function run(): array
    {
        $processed=0; $failed=0;
        $mailboxes=\db()->query('SELECT id,company_id FROM recruitment_mailboxes WHERE enabled=1 AND (last_sync IS NULL OR TIMESTAMPDIFF(MINUTE,last_sync,UTC_TIMESTAMP())>=interval_minutes) ORDER BY company_id,id')->fetchAll(\PDO::FETCH_ASSOC);
        foreach($mailboxes as $mailbox) {
            try {
                $modules=(new \App\Models\CompanyModule())->enabledForCompany((int)$mailbox['company_id']);
                if(!in_array('hr',array_column($modules,'code'),true)) continue;
                $service=new RecruitmentService(new Repository(\db(),(int)$mailbox['company_id']),new DocumentStore());
                $result=(new ImportService($service,new ImapProvider()))->sync((int)$mailbox['id']);
                $processed+=(int)$result['processed']; $failed+=(int)$result['failed'];
            } catch(\Throwable $e) { $failed++; }
        }
        return compact('processed','failed');
    }
}
