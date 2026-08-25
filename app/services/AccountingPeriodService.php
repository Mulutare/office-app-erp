<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class AccountingPeriodService
{
    public function __construct(private ?TenantContext $tenant = null)
    {
        $this->tenant ??= new TenantContext();
    }

    /** @return array{years:list<array<string,mixed>>,periods:list<array<string,mixed>>,history:list<array<string,mixed>>} */
    public function workspace(): array
    {
        $company = $this->tenant->companyId();
        $years = \db()->prepare('SELECT * FROM finance_fiscal_years WHERE company_id=:company ORDER BY date_from DESC');
        $years->execute(['company' => $company]);
        $periods = \db()->prepare('SELECT p.*,y.fiscal_year_name FROM finance_accounting_periods p LEFT JOIN finance_fiscal_years y ON y.company_id=p.company_id AND y.fiscal_year_id=p.fiscal_year_id WHERE p.company_id=:company ORDER BY p.date_from DESC');
        $periods->execute(['company' => $company]);
        $history = \db()->prepare('SELECT h.*,p.period_name FROM finance_accounting_period_history h INNER JOIN finance_accounting_periods p ON p.company_id=h.company_id AND p.period_id=h.period_id WHERE h.company_id=:company ORDER BY h.acted_at DESC,h.period_history_id DESC LIMIT 100');
        $history->execute(['company' => $company]);
        return ['years' => $years->fetchAll(PDO::FETCH_ASSOC), 'periods' => $periods->fetchAll(PDO::FETCH_ASSOC), 'history' => $history->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function createFiscalYear(array $input, int $actor): int
    {
        $company = $this->tenant->companyId();
        $name = trim((string)($input['fiscal_year_name'] ?? ''));
        $from = (string)($input['date_from'] ?? '');
        $to = (string)($input['date_to'] ?? '');
        $this->dates($from, $to);
        if ($name === '') throw new RuntimeException('Fiscal year name is required.');
        $overlap = \db()->prepare('SELECT COUNT(*) FROM finance_fiscal_years WHERE company_id=:company AND date_from<=:date_to AND date_to>=:date_from');
        $overlap->execute(['company'=>$company,'date_from'=>$from,'date_to'=>$to]);
        if ((int)$overlap->fetchColumn() > 0) throw new RuntimeException('Fiscal years cannot overlap.');
        $statement = \db()->prepare("INSERT INTO finance_fiscal_years(company_id,fiscal_year_name,date_from,date_to,status,created_by) VALUES(:company,:name,:date_from,:date_to,'open',:actor)");
        $statement->execute(['company'=>$company,'name'=>$name,'date_from'=>$from,'date_to'=>$to,'actor'=>$actor]);
        return (int)\db()->lastInsertId();
    }

    public function createPeriod(array $input, int $actor): int
    {
        $connection = \db(); $company = $this->tenant->companyId();
        $name = trim((string)($input['period_name'] ?? '')); $from=(string)($input['date_from']??''); $to=(string)($input['date_to']??''); $yearId=(int)($input['fiscal_year_id']??0);
        $this->dates($from,$to); if($name===''||$yearId<1) throw new RuntimeException('Fiscal year and period name are required.');
        $year=$connection->prepare('SELECT * FROM finance_fiscal_years WHERE company_id=:company AND fiscal_year_id=:year');$year->execute(['company'=>$company,'year'=>$yearId]);$yearRow=$year->fetch(PDO::FETCH_ASSOC);
        if(!$yearRow||$from<(string)$yearRow['date_from']||$to>(string)$yearRow['date_to']) throw new RuntimeException('The period must be inside the selected company fiscal year.');
        $overlap=$connection->prepare('SELECT COUNT(*) FROM finance_accounting_periods WHERE company_id=:company AND date_from<=:date_to AND date_to>=:date_from');$overlap->execute(['company'=>$company,'date_from'=>$from,'date_to'=>$to]);
        if((int)$overlap->fetchColumn()>0) throw new RuntimeException('Accounting periods cannot overlap.');
        try{$connection->beginTransaction();$insert=$connection->prepare("INSERT INTO finance_accounting_periods(company_id,fiscal_year_id,period_name,date_from,date_to,status,created_by) VALUES(:company,:year,:name,:date_from,:date_to,'open',:actor)");$insert->execute(['company'=>$company,'year'=>$yearId,'name'=>$name,'date_from'=>$from,'date_to'=>$to,'actor'=>$actor]);$id=(int)$connection->lastInsertId();$this->history($company,$id,'created',null,'open',null,$actor);$connection->commit();return$id;}catch(Throwable $e){if($connection->inTransaction())$connection->rollBack();throw$e;}
    }

    public function transition(int $periodId, string $action, ?string $reason, int $actor): void
    {
        $allowed=['close'=>['open','closed'],'lock'=>['closed','locked'],'reopen'=>[['closed','locked'],'open']];
        if(!isset($allowed[$action])) throw new RuntimeException('Unsupported accounting period action.');
        $connection=\db();$company=$this->tenant->companyId();$reason=trim((string)$reason);
        if($action==='reopen'&&$reason==='') throw new RuntimeException('A reopening reason is required.');
        try{$connection->beginTransaction();$query=$connection->prepare('SELECT * FROM finance_accounting_periods WHERE company_id=:company AND period_id=:period FOR UPDATE');$query->execute(['company'=>$company,'period'=>$periodId]);$period=$query->fetch(PDO::FETCH_ASSOC);if(!$period)throw new RuntimeException('Accounting period was not found.');
            [$required,$target]=$allowed[$action];$requiredStates=is_array($required)?$required:[$required];if(!in_array((string)$period['status'],$requiredStates,true))throw new RuntimeException('Accounting period is not in the required state.');$fromStatus=(string)$period['status'];
            if($action==='close'){$sql="UPDATE finance_accounting_periods SET status='closed',closed_by=:actor,closed_at=NOW() WHERE company_id=:company AND period_id=:period";}elseif($action==='lock'){$sql="UPDATE finance_accounting_periods SET status='locked',locked_by=:actor,locked_at=NOW() WHERE company_id=:company AND period_id=:period";}else{$sql="UPDATE finance_accounting_periods SET status='open',closed_by=NULL,closed_at=NULL,locked_by=NULL,locked_at=NULL WHERE company_id=:company AND period_id=:period";}
            $parameters=['company'=>$company,'period'=>$periodId];if($action!=='reopen')$parameters['actor']=$actor;$connection->prepare($sql)->execute($parameters);$this->history($company,$periodId,$action==='close'?'closed':($action==='lock'?'locked':'reopened'),$fromStatus,$target,$reason===''?null:$reason,$actor);$connection->commit();
        }catch(Throwable $e){if($connection->inTransaction())$connection->rollBack();throw$e;}
    }

    private function history(int $company,int $period,string $action,?string $from,string $to,?string $reason,int $actor): void
    {\db()->prepare('INSERT INTO finance_accounting_period_history(company_id,period_id,action,status_from,status_to,reason,acted_by) VALUES(:company,:period,:action,:from_status,:to_status,:reason,:actor)')->execute(['company'=>$company,'period'=>$period,'action'=>$action,'from_status'=>$from,'to_status'=>$to,'reason'=>$reason,'actor'=>$actor]);}
    private function dates(string $from,string $to): void
    {$a=DateTimeImmutable::createFromFormat('!Y-m-d',$from);$b=DateTimeImmutable::createFromFormat('!Y-m-d',$to);if(!$a||!$b||$a->format('Y-m-d')!==$from||$b->format('Y-m-d')!==$to||$to<$from)throw new RuntimeException('Valid start and end dates are required.');}
}
