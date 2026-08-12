<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use InvalidArgumentException;

final class StraightLineDepreciationCalculator
{
    /** @return list<array{period_number:int,depreciation_date:string,depreciation_amount:float,accumulated_amount:float,book_value_after:float}> */
    public function schedule(float $capitalizedCost,float $salvageValue,int $months,string $inServiceDate): array
    {
        $capitalizedCost=round($capitalizedCost,2);$salvageValue=round($salvageValue,2);
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$inServiceDate);
        if($capitalizedCost<=0||$salvageValue<0||$salvageValue>$capitalizedCost||$months<1||$date===false){throw new InvalidArgumentException('Valid cost, salvage value, useful life and in-service date are required.');}
        $depreciable=round($capitalizedCost-$salvageValue,2);if($depreciable<=0)return[];
        $regular=round($depreciable/$months,2);$accumulated=0.0;$rows=[];
        for($period=1;$period<=$months;$period++){
            $amount=$period===$months?round($depreciable-$accumulated,2):min($regular,round($depreciable-$accumulated,2));
            if($amount<=0)break;$accumulated=round($accumulated+$amount,2);
            $rows[]=['period_number'=>$period,'depreciation_date'=>$date->modify('+'.$period.' month')->format('Y-m-d'),'depreciation_amount'=>$amount,'accumulated_amount'=>$accumulated,'book_value_after'=>round($capitalizedCost-$accumulated,2)];
        }
        return $rows;
    }
}
