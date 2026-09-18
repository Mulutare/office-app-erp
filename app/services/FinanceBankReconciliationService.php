<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/** Bank-statement evidence and posted-GL clearing; never changes Sales settlements. */
final class FinanceBankReconciliationService
{
    private function company(): int { return (new TenantContext())->companyId(); }
    private function db(): PDO { return \db(); }
    private function date(string $value): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$d || $d->format('Y-m-d') !== $value) throw new RuntimeException('Enter a valid date.');
        return $value;
    }
    private function money(mixed $value): float
    {
        if (!is_scalar($value) || !preg_match('/^-?\d{1,15}(?:\.\d{1,2})?$/', trim((string)$value))) throw new RuntimeException('Enter an amount with at most two decimal places.');
        return round((float)$value, 2);
    }
    private function one(string $sql, array $params): array
    {
        $s=$this->db()->prepare($sql); $s->execute($params); $r=$s->fetch(PDO::FETCH_ASSOC);
        if (!is_array($r)) throw new RuntimeException('The requested bank record is unavailable.');
        return $r;
    }
    private function rows(string $sql, array $params): array
    { $s=$this->db()->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); }
    private function scalar(string $sql, array $params): float
    { $s=$this->db()->prepare($sql); $s->execute($params); return round((float)$s->fetchColumn(),2); }
    private function event(int $id, string $type, int $actor, ?string $from=null, ?string $to=null, ?string $reason=null): void
    {
        $this->db()->prepare('INSERT INTO finance_bank_reconciliation_events(company_id,reconciliation_id,event_type,from_status,to_status,reason,actor_id) VALUES(?,?,?,?,?,?,?)')
            ->execute([$this->company(),$id,$type,$from,$to,$reason,$actor]);
    }
    private function mapping(int $id, bool $lock=false): array
    {
        return $this->one('SELECT m.*,b.currency bank_currency,b.active bank_active,a.account_type,a.currency gl_currency,a.active gl_active,a.deleted_at,a.system_key,a.account_code,a.account_name FROM finance_bank_account_gl_mappings m JOIN company_bank_accounts b ON b.company_id=m.company_id AND b.bank_account_id=m.bank_account_id JOIN finance_accounts a ON a.company_id=m.company_id AND a.account_id=m.finance_account_id WHERE m.company_id=? AND m.mapping_id=?'.($lock?' FOR UPDATE':''),[$this->company(),$id]);
    }
    private function reconciliation(int $id, bool $lock=false): array
    {
        return $this->one('SELECT r.*,s.period_start,s.period_end,s.statement_date,s.statement_reference,s.opening_balance,s.ending_balance,m.effective_from,m.finance_account_id,m.status mapping_status,b.bank_name,b.account_name bank_account_name,b.account_number,a.account_code,a.account_name gl_account_name,pu.display_name preparer_name,ru.display_name reviewer_name FROM finance_bank_reconciliations r JOIN finance_bank_statements s ON s.company_id=r.company_id AND s.statement_id=r.statement_id JOIN finance_bank_account_gl_mappings m ON m.company_id=r.company_id AND m.mapping_id=r.mapping_id JOIN company_bank_accounts b ON b.company_id=r.company_id AND b.bank_account_id=r.bank_account_id JOIN finance_accounts a ON a.company_id=r.company_id AND a.account_id=r.gl_account_id JOIN users pu ON pu.user_id=r.prepared_by LEFT JOIN users ru ON ru.user_id=r.reviewed_by WHERE r.company_id=? AND r.reconciliation_id=?'.($lock?' FOR UPDATE':''),[$this->company(),$id]);
    }
    private function postedBalance(int $account, string $currency, string $date): float
    {
        return $this->scalar("SELECT COALESCE(SUM(e.debit_amount-e.credit_amount),0) FROM finance_journal_entries e JOIN finance_journal_batches b ON b.company_id=e.company_id AND b.journal_batch_id=e.journal_batch_id WHERE e.company_id=? AND e.account_id=? AND e.currency=? AND b.currency=? AND b.status='posted' AND b.posting_date<=?",[$this->company(),$account,$currency,$currency,$date]);
    }
    public function register(): array
    {
        $c=$this->company();
        return [
            'banks'=>$this->rows('SELECT bank_account_id,bank_name,account_name,account_number,currency,active FROM company_bank_accounts WHERE company_id=? ORDER BY bank_name,account_number',[$c]),
            'accounts'=>$this->rows("SELECT account_id,account_code,account_name,currency FROM finance_accounts WHERE company_id=? AND account_type='asset' AND currency IS NOT NULL AND system_key IS NULL AND active=TRUE AND deleted_at IS NULL ORDER BY account_code",[$c]),
            'mappings'=>$this->rows('SELECT m.*,b.bank_name,b.account_name bank_account_name,b.account_number,a.account_code,a.account_name,u.display_name approver_name FROM finance_bank_account_gl_mappings m JOIN company_bank_accounts b ON b.company_id=m.company_id AND b.bank_account_id=m.bank_account_id JOIN finance_accounts a ON a.company_id=m.company_id AND a.account_id=m.finance_account_id LEFT JOIN users u ON u.user_id=m.approved_by WHERE m.company_id=? ORDER BY m.mapping_id DESC',[$c]),
            'statements'=>$this->rows('SELECT s.*,b.bank_name,b.account_name bank_account_name,b.account_number,a.account_code,a.account_name gl_account_name,r.reconciliation_id,r.status reconciliation_status,pu.display_name preparer_name,ru.display_name reviewer_name FROM finance_bank_statements s JOIN company_bank_accounts b ON b.company_id=s.company_id AND b.bank_account_id=s.bank_account_id JOIN finance_bank_account_gl_mappings m ON m.company_id=s.company_id AND m.mapping_id=s.mapping_id JOIN finance_accounts a ON a.company_id=m.company_id AND a.account_id=m.finance_account_id LEFT JOIN finance_bank_reconciliations r ON r.company_id=s.company_id AND r.statement_id=s.statement_id LEFT JOIN users pu ON pu.user_id=r.prepared_by LEFT JOIN users ru ON ru.user_id=r.reviewed_by WHERE s.company_id=? ORDER BY s.period_end DESC,s.statement_id DESC',[$c]),
        ];
    }
    public function createMapping(array $input, int $actor): int
    {
        $c=$this->company(); $bank=(int)($input['bank_account_id']??0); $account=(int)($input['finance_account_id']??0);
        $effective=$this->date((string)($input['effective_from']??'')); $opening=$this->money($input['opening_gl_balance']??'');
        $reason=trim((string)($input['cutover_reason']??'')); if ($reason==='') throw new RuntimeException('Document the cutover decision.');
        $pdo=$this->db(); $pdo->beginTransaction();
        try {
            $b=$this->one('SELECT * FROM company_bank_accounts WHERE company_id=? AND bank_account_id=? AND active=TRUE FOR UPDATE',[$c,$bank]);
            $a=$this->one("SELECT * FROM finance_accounts WHERE company_id=? AND account_id=? AND active=TRUE AND deleted_at IS NULL AND account_type='asset' AND currency IS NOT NULL AND system_key IS NULL FOR UPDATE",[$c,$account]);
            if ($a['currency']!==$b['currency']) throw new RuntimeException('Bank and dedicated GL currency must match.');
            $overlap=$this->scalar("SELECT COUNT(*) FROM finance_bank_account_gl_mappings WHERE company_id=? AND status='draft' AND (bank_account_id=? OR finance_account_id=?)",[$c,$bank,$account]);
            if ($overlap>0) throw new RuntimeException('A draft mapping already uses this bank or GL account.');
            $current=$this->rows("SELECT * FROM finance_bank_account_gl_mappings WHERE company_id=? AND bank_account_id=? AND status='approved' AND effective_to IS NULL FOR UPDATE",[$c,$bank]);
            $bindings=$this->rows('SELECT * FROM finance_bank_gl_bindings WHERE company_id=? AND finance_account_id=? FOR UPDATE',[$c,$account]);
            $binding=$bindings[0]??null;
            if($binding!==null && ((int)$binding['bank_account_id']!==$bank || $binding['currency']!==$b['currency']))
                throw new RuntimeException('GL account permanently belongs to a different physical bank.');
            if($binding===null){
                $pdo->prepare('INSERT INTO finance_bank_gl_bindings(company_id,bank_account_id,finance_account_id,currency,created_by) VALUES(?,?,?,?,?)')
                    ->execute([$c,$bank,$account,$b['currency'],$actor]);
                $bindingId=(int)$pdo->lastInsertId();
            }else $bindingId=(int)$binding['binding_id'];
            $previous=$current[0]??null;
            if($previous!==null){
                if($effective<=$previous['effective_from'])throw new RuntimeException('Successor mapping must start after the current mapping.');
                $pending=$this->scalar("SELECT COUNT(*) FROM finance_bank_reconciliations WHERE company_id=? AND bank_account_id=? AND status IN('draft','in_review')",[$c,$bank]);
                if($pending>0)throw new RuntimeException('Complete the current statement before proposing a mapping change.');
                $last=$this->rows("SELECT MAX(s.period_end) period_end FROM finance_bank_statements s JOIN finance_bank_reconciliations r ON r.company_id=s.company_id AND r.statement_id=s.statement_id AND r.status='completed' WHERE s.company_id=? AND s.bank_account_id=?",[$c,$bank]);
                if(($last[0]['period_end']??null)!==null&&$effective<=(string)$last[0]['period_end'])throw new RuntimeException('Successor mapping must follow the last completed statement.');
            }
            $prior=$this->date((new \DateTimeImmutable($effective))->modify('-1 day')->format('Y-m-d'));
            $actual=$this->postedBalance($account,(string)$b['currency'],$prior);
            if (abs($actual-$opening)>0.001) throw new RuntimeException('Opening GL balance must equal the actual posted balance immediately before cutover.');
            $s=$pdo->prepare('INSERT INTO finance_bank_account_gl_mappings(company_id,bank_account_id,finance_account_id,binding_id,currency,effective_from,opening_gl_balance,cutover_reason,replaces_mapping_id,created_by) VALUES(?,?,?,?,?,?,?,?,?,?)');
            $s->execute([$c,$bank,$account,$bindingId,$b['currency'],$effective,$opening,$reason,$previous['mapping_id']??null,$actor]);
            $id=(int)$pdo->lastInsertId(); $pdo->commit(); return $id;
        } catch (\Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
    }
    public function approveMapping(int $id, int $actor): void
    {
        $pdo=$this->db(); $pdo->beginTransaction();
        try {
            $m=$this->mapping($id,true);
            if ($m['status']!=='draft'||(int)$m['created_by']===$actor) throw new RuntimeException('An independent approver must approve a draft mapping.');
            if (!(bool)$m['bank_active']||!(bool)$m['gl_active']||$m['deleted_at']!==null||$m['account_type']!=='asset'||$m['gl_currency']===null||$m['gl_currency']!==$m['bank_currency']||$m['system_key']!==null) throw new RuntimeException('Bank or GL account is no longer eligible.');
            $prior=(new \DateTimeImmutable($m['effective_from']))->modify('-1 day')->format('Y-m-d');
            if (abs($this->postedBalance((int)$m['finance_account_id'],$m['currency'],$prior)-(float)$m['opening_gl_balance'])>0.001) throw new RuntimeException('Posted opening GL balance changed; prepare a new cutover.');
            if($m['replaces_mapping_id']!==null){
                $old=$this->mapping((int)$m['replaces_mapping_id'],true);
                if($old['status']!=='approved'||$old['effective_to']!==null||(int)$old['bank_account_id']!==(int)$m['bank_account_id'])throw new RuntimeException('Predecessor mapping changed.');
                $pending=$this->scalar("SELECT COUNT(*) FROM finance_bank_reconciliations WHERE company_id=? AND bank_account_id=? AND status IN('draft','in_review')",[$this->company(),$m['bank_account_id']]);
                if($pending>0)throw new RuntimeException('Complete the current statement before changing mapping.');
                $last=$this->rows("SELECT r.deposits_in_transit,r.outstanding_payments,s.ending_balance,s.period_end FROM finance_bank_reconciliations r JOIN finance_bank_statements s ON s.company_id=r.company_id AND s.statement_id=r.statement_id WHERE r.company_id=? AND r.bank_account_id=? AND r.status='completed' ORDER BY s.period_end DESC LIMIT 1",[$this->company(),$m['bank_account_id']]);
                if($last && ((float)$last[0]['deposits_in_transit']>0.001||(float)$last[0]['outstanding_payments']>0.001))throw new RuntimeException('Clear or resolve outstanding book items before changing the mapped GL account.');
                if($last && abs((float)$last[0]['ending_balance']-(float)$m['opening_gl_balance'])>0.001)throw new RuntimeException('New mapped GL cutover balance must equal the last statement ending balance.');
                if($last && $m['effective_from']!==(new \DateTimeImmutable($last[0]['period_end']))->modify('+1 day')->format('Y-m-d'))throw new RuntimeException('Successor mapping must begin the day after the last completed statement.');
                $pdo->prepare("UPDATE finance_bank_account_gl_mappings SET status='superseded',effective_to=?,superseded_by_mapping_id=?,superseded_at=NOW() WHERE company_id=? AND mapping_id=?")
                    ->execute([$prior,$id,$this->company(),$old['mapping_id']]);
            }
            $pdo->prepare("UPDATE finance_bank_account_gl_mappings SET status='approved',approved_by=?,approved_at=NOW() WHERE company_id=? AND mapping_id=?") ->execute([$actor,$this->company(),$id]);
            $pdo->commit();
        } catch (\Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
    }
    public function createStatement(array $input, int $actor): int
    {
        $c=$this->company(); $mappingId=(int)($input['mapping_id']??0);
        $from=$this->date((string)($input['period_start']??'')); $to=$this->date((string)($input['period_end']??'')); $dated=$this->date((string)($input['statement_date']??''));
        $open=$this->money($input['opening_balance']??''); $end=$this->money($input['ending_balance']??'');
        $reference=trim((string)($input['statement_reference']??''));
        if ($reference===''||$from>$to||$dated<$to) throw new RuntimeException('Statement reference or period is invalid.');
        $pdo=$this->db(); $pdo->beginTransaction();
        try {
            $m=$this->mapping($mappingId,true);
            if ($m['status']!=='approved'||$from<$m['effective_from']||$m['effective_to']!==null||!(bool)$m['bank_active']||!(bool)$m['gl_active']||$m['deleted_at']!==null||$m['account_type']!=='asset'||$m['system_key']!==null||$m['gl_currency']!==$m['currency']||$m['bank_currency']!==$m['currency']) throw new RuntimeException('Statement requires a current approved, active and currency-consistent mapping.');
            $pending=$this->scalar("SELECT COUNT(*) FROM finance_bank_reconciliations WHERE company_id=? AND bank_account_id=? AND status IN('draft','in_review')",[$c,$m['bank_account_id']]);
            if($pending>0)throw new RuntimeException('Complete the current bank statement before starting another.');
            $previous=$this->rows("SELECT s.period_end,s.ending_balance FROM finance_bank_statements s JOIN finance_bank_reconciliations r ON r.company_id=s.company_id AND r.statement_id=s.statement_id AND r.status='completed' WHERE s.company_id=? AND s.bank_account_id=? ORDER BY s.period_end DESC LIMIT 1",[$c,$m['bank_account_id']]);
            if ($previous) {
                $p=$previous[0]; $next=(new \DateTimeImmutable($p['period_end']))->modify('+1 day')->format('Y-m-d');
                if ($from!==$next||abs((float)$p['ending_balance']-$open)>0.001) throw new RuntimeException('Statement must continue from the preceding completed period and ending balance.');
                if($m['replaces_mapping_id']!==null&&$from===$m['effective_from']&&abs((float)$m['opening_gl_balance']-$open)>0.001)throw new RuntimeException('Changed GL mapping requires the approved cutover balance.');
            } elseif ($from!==$m['effective_from']||abs((float)$m['opening_gl_balance']-$open)>0.001) throw new RuntimeException('First statement must start at cutover with its approved opening GL balance.');
            $busy=$this->scalar("SELECT COUNT(*) FROM finance_bank_statements s JOIN finance_bank_reconciliations r ON r.company_id=s.company_id AND r.statement_id=s.statement_id WHERE s.company_id=? AND s.bank_account_id=? AND r.status<>'superseded' AND s.period_start<=? AND s.period_end>=?",[$c,$m['bank_account_id'],$to,$from]);
            if($busy>0) throw new RuntimeException('A statement already covers this period.');
            $s=$pdo->prepare("INSERT INTO finance_bank_statements(company_id,bank_account_id,mapping_id,currency,statement_reference,period_start,period_end,statement_date,opening_balance,ending_balance,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)");
            $s->execute([$c,$m['bank_account_id'],$mappingId,$m['currency'],$reference,$from,$to,$dated,$open,$end,$actor]);
            $statementId=(int)$pdo->lastInsertId();
            $s=$pdo->prepare("INSERT INTO finance_bank_reconciliations(company_id,bank_account_id,mapping_id,statement_id,currency,gl_account_id,statement_opening_balance,statement_ending_balance,prepared_by,prepared_at) VALUES(?,?,?,?,?,?,?,?,?,NOW())");
            $s->execute([$c,$m['bank_account_id'],$mappingId,$statementId,$m['currency'],$m['finance_account_id'],$open,$end,$actor]);
            $id=(int)$pdo->lastInsertId(); $this->event($id,'started',$actor,null,'draft',$reference); $pdo->commit(); return $id;
        } catch (\Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
    }
    public function addLine(int $id, array $input, int $actor): void
    {
        $date=$this->date((string)($input['transaction_date']??'')); $value=trim((string)($input['value_date']??'')); if($value!=='')$this->date($value);
        $direction=trim((string)($input['direction']??'')); $amount=$this->money($input['amount']??'');
        $description=trim((string)($input['description']??'')); if(!in_array($direction,['credit','debit'],true)||$amount<=0||$description==='')throw new RuntimeException('Statement line direction, description and positive amount are required.');
        $pdo=$this->db(); $pdo->beginTransaction();
        try {
            $r=$this->reconciliation($id,true); if($r['status']!=='draft')throw new RuntimeException('Only draft statements accept new lines.');
            if($date<$r['period_start']||$date>$r['period_end'])throw new RuntimeException('Statement line date is outside the period.');
            $n=(int)$this->scalar('SELECT COALESCE(MAX(line_number),0)+1 FROM finance_bank_statement_lines WHERE company_id=? AND statement_id=?',[$this->company(),$r['statement_id']]);
            $pdo->prepare('INSERT INTO finance_bank_statement_lines(company_id,statement_id,bank_account_id,line_number,transaction_date,value_date,reference_number,description,direction,amount,currency,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$this->company(),$r['statement_id'],$r['bank_account_id'],$n,$date,$value?:null,trim((string)($input['reference_number']??''))?:null,$description,$direction,$amount,$r['currency'],$actor]);
            $this->event($id,'statement_line_added',$actor); $pdo->commit();
        } catch (\Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
    }
    private function bookRows(array $r): array
    {
        return $this->rows("SELECT e.journal_entry_id,e.debit_amount,e.credit_amount,e.currency,e.description,b.posting_date,b.batch_number,b.source_type,b.source_number,b.status, COALESCE((SELECT SUM(m.applied_amount) FROM finance_bank_reconciliation_matches m JOIN finance_bank_reconciliations x ON x.company_id=m.company_id AND x.reconciliation_id=m.reconciliation_id WHERE m.company_id=e.company_id AND m.journal_entry_id=e.journal_entry_id AND m.removed_at IS NULL AND x.status IN('draft','in_review','completed')),0) cleared_amount FROM finance_journal_entries e JOIN finance_journal_batches b ON b.company_id=e.company_id AND b.journal_batch_id=e.journal_batch_id WHERE e.company_id=? AND e.account_id=? AND e.currency=? AND b.currency=? AND b.status='posted' AND b.posting_date BETWEEN ? AND ? ORDER BY b.posting_date,e.journal_entry_id",[$this->company(),$r['gl_account_id'],$r['currency'],$r['currency'],$r['effective_from'],$r['period_end']]);
    }
    private function lineRows(array $r): array
    {
        return $this->rows("SELECT l.*,COALESCE((SELECT SUM(m.applied_amount) FROM finance_bank_reconciliation_matches m WHERE m.company_id=l.company_id AND m.statement_line_id=l.statement_line_id AND m.removed_at IS NULL),0) cleared_amount FROM finance_bank_statement_lines l WHERE l.company_id=? AND l.statement_id=? ORDER BY l.line_number",[$this->company(),$r['statement_id']]);
    }
    private function figures(array $r, array $lines, array $books): array
    {
        $movement=0.0; $unmatched=0; foreach($lines as $l){$movement+=($l['direction']==='credit'?1:-1)*(float)$l['amount']; if((float)$l['amount']-(float)$l['cleared_amount']>0.001)$unmatched++;}
        $deposits=0.0; $payments=0.0; foreach($books as $b){$amount=max((float)$b['debit_amount'],(float)$b['credit_amount']);$left=round($amount-(float)$b['cleared_amount'],2);if($left>0){if((float)$b['debit_amount']>0)$deposits+=$left;else $payments+=$left;}}
        $gl=$this->postedBalance((int)$r['gl_account_id'],$r['currency'],$r['period_end']); $adjusted=round((float)$r['ending_balance']+$deposits-$payments,2);
        return ['gl_closing_balance'=>$gl,'deposits_in_transit'=>round($deposits,2),'outstanding_payments'=>round($payments,2),'adjusted_bank_balance'=>$adjusted,'reconciliation_difference'=>round($adjusted-$gl,2),'unmatched_statement_count'=>$unmatched,'statement_movement_difference'=>round((float)$r['opening_balance']+$movement-(float)$r['ending_balance'],2)];
    }
    public function worksheet(int $id): array
    {
        $r=$this->reconciliation($id);$lines=$this->lineRows($r);$books=$this->bookRows($r);
        return ['reconciliation'=>$r,'lines'=>$lines,'books'=>$books,'figures'=>$r['status']==='completed'?array_intersect_key($r,array_flip(['gl_closing_balance','deposits_in_transit','outstanding_payments','adjusted_bank_balance','reconciliation_difference'])):$this->figures($r,$lines,$books),'matches'=>$this->rows('SELECT m.*,l.line_number,b.batch_number FROM finance_bank_reconciliation_matches m JOIN finance_bank_statement_lines l ON l.company_id=m.company_id AND l.statement_line_id=m.statement_line_id JOIN finance_journal_entries e ON e.company_id=m.company_id AND e.journal_entry_id=m.journal_entry_id JOIN finance_journal_batches b ON b.company_id=e.company_id AND b.journal_batch_id=e.journal_batch_id WHERE m.company_id=? AND m.reconciliation_id=? ORDER BY m.match_id',[$this->company(),$id]),'events'=>$this->rows('SELECT e.*,u.display_name actor_name FROM finance_bank_reconciliation_events e JOIN users u ON u.user_id=e.actor_id WHERE e.company_id=? AND e.reconciliation_id=? ORDER BY e.event_id',[$this->company(),$id])];
    }
    public function match(int $id, int $lineId, int $entryId, mixed $amountInput, int $actor): void
    {
        $amount=$this->money($amountInput); if($amount<=0)throw new RuntimeException('Match amount must be positive.');
        $pdo=$this->db();$pdo->beginTransaction();
        try {
            $r=$this->reconciliation($id,true);if($r['status']!=='draft')throw new RuntimeException('Only draft reconciliations can be matched.');
            $this->mapping((int)$r['mapping_id'],true); // serializes all clearing on this mapped account
            $l=$this->one('SELECT * FROM finance_bank_statement_lines WHERE company_id=? AND statement_id=? AND statement_line_id=? FOR UPDATE',[$this->company(),$r['statement_id'],$lineId]);
            $b=$this->one("SELECT e.*,j.posting_date,j.status,j.currency batch_currency FROM finance_journal_entries e JOIN finance_journal_batches j ON j.company_id=e.company_id AND j.journal_batch_id=e.journal_batch_id WHERE e.company_id=? AND e.journal_entry_id=? AND e.account_id=? FOR UPDATE",[$this->company(),$entryId,$r['gl_account_id']]);
            if($b['status']!=='posted'||$b['currency']!==$r['currency']||$b['batch_currency']!==$r['currency']||$l['currency']!==$r['currency']||$b['posting_date']<$r['effective_from']||$b['posting_date']>$r['period_end'])throw new RuntimeException('Book entry is not eligible for this statement.');
            if(($l['direction']==='credit')!==((float)$b['debit_amount']>0))throw new RuntimeException('Statement and book directions differ.');
            $lineUsed=$this->scalar('SELECT COALESCE(SUM(applied_amount),0) FROM finance_bank_reconciliation_matches WHERE company_id=? AND statement_line_id=? AND removed_at IS NULL',[$this->company(),$lineId]);
            $bookUsed=$this->scalar("SELECT COALESCE(SUM(m.applied_amount),0) FROM finance_bank_reconciliation_matches m JOIN finance_bank_reconciliations x ON x.company_id=m.company_id AND x.reconciliation_id=m.reconciliation_id WHERE m.company_id=? AND m.journal_entry_id=? AND m.removed_at IS NULL AND x.status IN('draft','in_review','completed')",[$this->company(),$entryId]);
            if($lineUsed+$amount>(float)$l['amount']+0.001||$bookUsed+$amount>max((float)$b['debit_amount'],(float)$b['credit_amount'])+0.001)throw new RuntimeException('Match exceeds the remaining statement or book amount.');
            $pdo->prepare('INSERT INTO finance_bank_reconciliation_matches(company_id,reconciliation_id,statement_line_id,journal_entry_id,applied_amount,created_by) VALUES(?,?,?,?,?,?)')->execute([$this->company(),$id,$lineId,$entryId,$amount,$actor]);
            $this->event($id,'match_added',$actor,null,null,'Statement line '.$lineId.' / GL line '.$entryId);$pdo->commit();
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    public function unmatch(int $id,int $matchId,int $actor): void
    {
        $pdo=$this->db();$pdo->beginTransaction();
        try{$r=$this->reconciliation($id,true);if($r['status']!=='draft')throw new RuntimeException('Only draft matches can be removed.');$this->mapping((int)$r['mapping_id'],true);
            $m=$this->one('SELECT * FROM finance_bank_reconciliation_matches WHERE company_id=? AND reconciliation_id=? AND match_id=? AND removed_at IS NULL FOR UPDATE',[$this->company(),$id,$matchId]);
            $pdo->prepare('UPDATE finance_bank_reconciliation_matches SET removed_at=NOW(),removed_by=? WHERE company_id=? AND match_id=?')->execute([$actor,$this->company(),$matchId]);
            $this->event($id,'match_removed',$actor,null,null,'Match '.$m['match_id']);$pdo->commit();
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    public function sendForReview(int $id,int $actor): void
    {
        $pdo=$this->db();$pdo->beginTransaction();
        try{$r=$this->reconciliation($id,true);if($r['status']!=='draft'||(int)$r['prepared_by']!==$actor)throw new RuntimeException('Only the preparer may send a draft for review.');
            $pdo->prepare("UPDATE finance_bank_reconciliations SET status='in_review' WHERE company_id=? AND reconciliation_id=?")->execute([$this->company(),$id]);$this->event($id,'sent_for_review',$actor,'draft','in_review');$pdo->commit();
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    public function review(int $id,bool $complete,string $reason,int $actor): void
    {
        $pdo=$this->db();$pdo->beginTransaction();
        try{$r=$this->reconciliation($id,true);if($r['status']!=='in_review'||(int)$r['prepared_by']===$actor)throw new RuntimeException('An independent reviewer is required.');
            $m=$this->mapping((int)$r['mapping_id'],true);if($m['status']!=='approved'||$m['effective_to']!==null||!(bool)$m['bank_active']||!(bool)$m['gl_active']||$m['deleted_at']!==null||$m['account_type']!=='asset'||$m['system_key']!==null||$m['gl_currency']!==$r['currency']||$m['bank_currency']!==$r['currency'])throw new RuntimeException('Mapping is no longer current or eligible.');
            $participated=$this->scalar('SELECT (SELECT COUNT(*) FROM finance_bank_statement_lines WHERE company_id=? AND statement_id=? AND created_by=?) + (SELECT COUNT(*) FROM finance_bank_reconciliation_matches WHERE company_id=? AND reconciliation_id=? AND (created_by=? OR removed_by=?)) + (SELECT COUNT(*) FROM finance_bank_statements WHERE company_id=? AND statement_id=? AND created_by=?)',[$this->company(),$r['statement_id'],$actor,$this->company(),$id,$actor,$actor,$this->company(),$r['statement_id'],$actor]);
            if($participated>0)throw new RuntimeException('A statement preparer or matching actor cannot review the same reconciliation.');
            if(!$complete){if(trim($reason)==='')throw new RuntimeException('A rejection reason is required.');$pdo->prepare("UPDATE finance_bank_reconciliations SET status='draft',reviewed_by=?,reviewed_at=NOW(),review_reason=? WHERE company_id=? AND reconciliation_id=?")->execute([$actor,$reason,$this->company(),$id]);$this->event($id,'review_rejected',$actor,'in_review','draft',$reason);$pdo->commit();return;}
            $invalidMatches=$this->scalar("SELECT COUNT(*) FROM finance_bank_reconciliation_matches x
                LEFT JOIN finance_bank_statement_lines l ON l.company_id=x.company_id AND l.statement_line_id=x.statement_line_id
                LEFT JOIN finance_journal_entries e ON e.company_id=x.company_id AND e.journal_entry_id=x.journal_entry_id
                LEFT JOIN finance_journal_batches j ON j.company_id=e.company_id AND j.journal_batch_id=e.journal_batch_id
                WHERE x.company_id=? AND x.reconciliation_id=? AND x.removed_at IS NULL
                  AND (l.statement_line_id IS NULL OR l.statement_id<>? OR l.currency<>?
                    OR e.journal_entry_id IS NULL OR e.account_id<>? OR e.currency<>?
                    OR j.journal_batch_id IS NULL OR j.status<>'posted' OR j.currency<>?
                    OR j.posting_date<? OR j.posting_date>?
                    OR (l.direction='credit' AND e.debit_amount<=0)
                    OR (l.direction='debit' AND e.credit_amount<=0))",
                [$this->company(),$id,$r['statement_id'],$r['currency'],$r['gl_account_id'],$r['currency'],$r['currency'],$r['effective_from'],$r['period_end']]);
            if($invalidMatches>0)throw new RuntimeException('A cleared statement or book item is no longer eligible for completion.');
            $lines=$this->lineRows($r);$books=$this->bookRows($r);$f=$this->figures($r,$lines,$books);
            if(!$lines||abs($f['statement_movement_difference'])>0.001||$f['unmatched_statement_count']>0||abs($f['reconciliation_difference'])>0.001)throw new RuntimeException('Statement movement, statement items and GL difference must all reconcile before completion.');
            foreach($lines as $l)if(abs((float)$l['amount']-(float)$l['cleared_amount'])>0.001)throw new RuntimeException('A statement line is not exactly cleared.');
            foreach($books as $b)if((float)$b['cleared_amount']>max((float)$b['debit_amount'],(float)$b['credit_amount'])+0.001)throw new RuntimeException('A GL line is over-cleared.');
            $pdo->prepare("UPDATE finance_bank_reconciliations SET status='completed',gl_closing_balance=?,deposits_in_transit=?,outstanding_payments=?,adjusted_bank_balance=?,reconciliation_difference=?,reviewed_by=?,reviewed_at=NOW(),completed_by=?,completed_at=NOW(),review_reason=? WHERE company_id=? AND reconciliation_id=?")
                ->execute([$f['gl_closing_balance'],$f['deposits_in_transit'],$f['outstanding_payments'],$f['adjusted_bank_balance'],$f['reconciliation_difference'],$actor,$actor,$reason?:null,$this->company(),$id]);
            $this->event($id,'completed',$actor,'in_review','completed',$reason?:null);$pdo->commit();
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
}
