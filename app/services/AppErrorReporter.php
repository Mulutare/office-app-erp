<?php

declare(strict_types=1);

namespace App\Services;

use PDOException;
use Throwable;

final class AppErrorReporter
{
    /** @return array{code:string,title:string,cause:string,suggested_action:string,incident_reference:string} */
    public function report(string $code, ?Throwable $exception = null, array $context = []): array
    {
        try {
            $definition = $this->definition($code) ?? $this->definition('SYS-UNEXPECTED-001');
            if ($definition === null) throw new \RuntimeException('The generic error definition is unavailable.');
            $reference = $this->reference();
            if (!empty($definition['log_incident'])) {
                $statement = \db()->prepare(
                    'INSERT INTO app_error_incidents(incident_reference,error_code,company_id,user_id,module,route,entity_type,entity_id,exception_class,safe_internal_message,context_json,occurred_at)
                     VALUES(:reference,:code,:company_id,:user_id,:module,:route,:entity_type,:entity_id,:exception_class,:message,:context,NOW())'
                );
                for ($attempt = 0; $attempt < 3; $attempt++) {
                    try {
                        $statement->execute([
                            'reference'=>$reference,
                            'code'=>$definition['error_code'],
                            'company_id'=>$this->positiveOrNull($_SESSION['auth']['company']['company_id']??null),
                            'user_id'=>$this->positiveOrNull($_SESSION['auth']['user_id']??null),
                            'module'=>$definition['module'],
                            'route'=>$this->text($context['route']??($_SERVER['REQUEST_URI']??null),255),
                            'entity_type'=>$this->text($context['entity_type']??null,80),
                            'entity_id'=>$this->text($context['entity_id']??null,100),
                            'exception_class'=>$exception!==null?$exception::class:null,
                            'message'=>$exception!==null?$this->text($exception->getMessage(),1000):null,
                            'context'=>$this->contextJson($context),
                        ]);
                        break;
                    } catch (PDOException $databaseError) {
                        if ($databaseError->getCode() !== '23000' || $attempt === 2) throw $databaseError;
                        $reference = $this->reference();
                    }
                }
            }
            if ($exception !== null) error_log('Application incident '.$reference.' code='.$definition['error_code'].' class='.$exception::class);
            return $this->payload($definition,$reference);
        } catch (Throwable $reportingFailure) {
            $reference = $this->reference();
            error_log('Application incident reporting failed reference='.$reference.' class='.$reportingFailure::class);
            return ['code'=>'SYS-UNEXPECTED-001','title'=>'Unexpected application error','cause'=>'An unexpected error prevented the operation from completing.','suggested_action'=>'Try again. If it continues, provide the incident reference to an administrator.','incident_reference'=>$reference];
        }
    }

    public function definition(string $code): ?array
    {
        $statement=\db()->prepare('SELECT * FROM app_error_catalog WHERE error_code=:code AND active=TRUE LIMIT 1');
        $statement->execute(['code'=>$code]);
        $row=$statement->fetch(\PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }

    private function reference(): string{return 'ERR-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(6)));}
    private function positiveOrNull(mixed $value): ?int{$id=(int)$value;return$id>0?$id:null;}
    private function text(mixed $value,int $max): ?string{$text=trim((string)$value);return$text===''?null:substr($text,0,$max);}
    private function contextJson(array $context): ?string
    {
        $safe=[];
        foreach($context as $key=>$value){$normalized=strtolower((string)$key);if(preg_match('/password|token|authorization|cookie|csrf|secret/',$normalized))continue;if(is_scalar($value)||$value===null)$safe[$key]=is_string($value)?substr($value,0,500):$value;}
        return $safe===[]?null:json_encode($safe,JSON_THROW_ON_ERROR);
    }
    private function payload(array $definition,string $reference): array{return['code'=>(string)$definition['error_code'],'title'=>(string)$definition['title'],'cause'=>(string)$definition['cause'],'suggested_action'=>(string)$definition['suggested_action'],'incident_reference'=>$reference];}
}
