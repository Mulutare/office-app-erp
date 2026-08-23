<?php
declare(strict_types=1);
namespace App\Services;

final class EtbAmountInWords
{
    private const SMALL=['Zero','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'];
    private const TENS=['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    public function convert(float|string $amount): string
    {
        $minor=(int)round((float)$amount*100,0,PHP_ROUND_HALF_UP);$birr=intdiv($minor,100);$cents=$minor%100;
        $words=$this->integer($birr).' Ethiopian Birr';
        if($cents>0)$words.=' and '.$this->integer($cents).' Cents';
        return $words.' Only';
    }
    private function integer(int $number): string
    {
        if($number<20)return self::SMALL[$number];
        if($number<100)return self::TENS[intdiv($number,10)].($number%10?'‑'.self::SMALL[$number%10]:'');
        if($number<1000)return self::SMALL[intdiv($number,100)].' Hundred'.($number%100?' '.$this->integer($number%100):'');
        foreach([[1000000000000,'Trillion'],[1000000000,'Billion'],[1000000,'Million'],[1000,'Thousand']] as [$scale,$name]){
            if($number>=$scale)return $this->integer(intdiv($number,$scale)).' '.$name.($number%$scale?' '.$this->integer($number%$scale):'');
        }
        throw new \InvalidArgumentException('Amount is outside the supported business range.');
    }
}
