<?php
declare(strict_types=1);
namespace App\Services\Lists;

/** Categorical controls use declared SQL domains or the complete authorized register. */
final class FilterOptions
{
    private static array $domains=[];

    public static function domain(string $table,string $column): array
    {
        $key=$table.'.'.$column;
        if(isset(self::$domains[$key]))return self::$domains[$key];
        $db=\db();$values=[];
        $query=$db->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $query->execute([$table,$column]);$type=(string)$query->fetchColumn();
        if(preg_match('/^(?:enum|set)\((.*)\)$/i',$type,$match))$values=self::literals($match[1]);
        if(!$values) {
            $query=$db->prepare("SELECT cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc
                JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME
                WHERE tc.TABLE_SCHEMA=DATABASE() AND tc.TABLE_NAME=? AND tc.CONSTRAINT_TYPE='CHECK'");
            $query->execute([$table]);
            foreach($query->fetchAll(\PDO::FETCH_COLUMN) as $clause) {
                // Only direct IN domains, never conditional workflow checks involving other columns.
                if(preg_match('/^\\(*\\s*`?'.preg_quote($column,'/').'`?\\s+in\\s*\\(([^()]*)\\)\\s*\\)*$/i',trim($clause),$match)) {
                    $values=self::literals($match[1]);break;
                }
            }
        }
        return self::$domains[$key]=self::labels($values);
    }

    private static function literals(string $source): array
    {
        preg_match_all("/'((?:''|[^'])*)'/",$source,$matches);
        return array_map(static fn(string $value):string=>str_replace("''","'",$value),$matches[1]);
    }

    public static function labels(array $values): array
    {
        $result=[];foreach($values as $value)$result[$value]=ucwords(str_replace('_',' ',(string)$value));return $result;
    }

    /** $domains maps a filter to [table,column] or an explicit value=>label domain. */
    public static function controls(SqlList $list,array $filters,array $sorts,array $domains=[],array $labels=[]): array
    {
        $controls=[];
        foreach($filters as $key=>$column) {
            $label=ucwords(str_replace('_',' ',$key));
            if(in_array($key,['from','to','date_from','date_to','date'],true)) {$controls[$key]=['label'=>$label,'type'=>'date'];continue;}
            if($key==='month'){$controls[$key]=['label'=>$label,'type'=>'month'];continue;}
            $domain=$domains[$key]??null;
            if($domain!==null && array_is_list($domain) && count($domain)===2)$options=self::domain($domain[0],$domain[1]);
            else $options=$domain??[];
            if(!$options)$options=$list->options(is_array($column)?$column[0]:$column,$labels[$key]??null);
            if($domain!==null && array_is_list($domain))$options=array_map(static fn($value)=>ucwords(str_replace('_',' ',(string)$value)),$options);
            $controls[$key]=['label'=>$label,'options'=>$options];
        }
        return ['sorts'=>self::labels(array_keys($sorts)),'filters'=>$controls];
    }
}
