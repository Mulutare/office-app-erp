<?php
declare(strict_types=1);
namespace App\Services\Recruitment;

final class ScreeningService
{
    /** Pure decision support. Never changes application workflow status. */
    public function evaluate(array $criteria,array $answers): array
    {
        $results=[]; $fail=0; $review=0; $pass=0; $preference=0;
        foreach ($criteria as $c) {
            $raw=$answers[$c['code']]??null; $value=null; $outcome='needs_review'; $reason='Missing or ambiguous answer; human review required.';
            try { $value=CareersContract::answer($c,$raw); } catch (\InvalidArgumentException $e) { /* Keep unknown information for review. */ }
            if ($value!==null) {
                if ($c['decision_mode']==='review') $reason='This question requires human judgment.';
                else {
                    $expected=$c['expected'];
                    $met=match($c['operator']) {
                        'gte'=>(float)$value >= (float)$expected, 'lte'=>(float)$value <= (float)$expected,
                        'in'=>is_array($value) ? !array_diff($value,$expected) : in_array($value,$expected,true),
                        'contains'=>is_array($value) && !array_diff((array)$expected,$value),
                        default=>is_numeric($value) && $c['criterion_type']==='experience_years' ? (float)$value===(float)$expected : $value===$expected,
                    };
                    $outcome=$met?'minimum_met':'minimum_not_met';
                    $reason=$met?'Explicit answer meets the configured requirement.':'Explicit answer does not meet the configured requirement.';
                }
            }
            if ($c['decision_mode']==='preference') { $preference++; $outcome='preference'; $reason='Preference only; does not disqualify.'; }
            elseif ($outcome==='minimum_not_met') $fail++;
            elseif ($outcome==='minimum_met') $pass++;
            elseif ($outcome==='needs_review') $review++;
            $results[]=['code'=>$c['code'],'label'=>$c['label'],'required'=>$c['expected'],'comparison'=>$c['operator'],'answer'=>$raw,'normalized'=>$value,'evaluation'=>$outcome,'reason'=>$reason];
        }
        return ['outcome'=>$fail?'minimum_not_met':($review||!$criteria?'needs_review':'minimum_met'),'minimum_pass_count'=>$pass,'minimum_fail_count'=>$fail,'review_count'=>$review,'preference_count'=>$preference,'answers'=>$results];
    }
}
