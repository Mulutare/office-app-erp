<?php
declare(strict_types=1);
namespace App\Services\Recruitment;

/** Portable contract: also shipped with the isolated public Careers component. */
final class CareersContract
{
    public const MAX_BODY = 29000000;
    public const MAX_FILE = 10485760;
    public const TYPES = ['experience_years','education_level','field_of_study','boolean','single_choice','multiple_choice','text','location','document_required'];
    public static function json(string $body, int $limit = self::MAX_BODY): array
    {
        if (strlen($body) > $limit) throw new \InvalidArgumentException('Payload exceeds limit.');
        try { $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { throw new \InvalidArgumentException('Invalid JSON.'); }
        if (!is_array($data) || array_is_list($data)) throw new \InvalidArgumentException('JSON object required.');
        return $data;
    }
    public static function criterion(array $c): array
    {
        $type = $c['criterion_type'] ?? ''; $mode = $c['decision_mode'] ?? 'review'; $op = $c['operator'] ?? 'eq';
        $label = trim((string)($c['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 254 || !in_array($type, self::TYPES, true) || !in_array($mode, ['minimum','review','preference'], true)) throw new \InvalidArgumentException('Choose a question, answer type and requirement.');
        // Free text and fields of study always require human interpretation.
        if (in_array($type, ['text','field_of_study'], true) && $mode === 'minimum') $mode = 'review';
        $allowed = match ($type) { 'experience_years' => ['gte','lte','eq'], 'multiple_choice' => ['contains','in'], default => ['eq','in'] };
        if (!in_array($op, $allowed, true)) throw new \InvalidArgumentException('Comparison does not fit this answer type.');
        $options = $c['options'] ?? []; $expected = $c['expected'] ?? null;
        if (!is_array($options) || !array_is_list($options) || count($options) > 30) throw new \InvalidArgumentException('Use at most 30 choices.');
        foreach ($options as $value) if (!is_string($value) || trim($value) === '' || mb_strlen($value) > 150) throw new \InvalidArgumentException('Invalid choice.');
        if (count(array_unique($options)) !== count($options)) throw new \InvalidArgumentException('Choices must be distinct.');
        if (in_array($type, ['education_level','single_choice','multiple_choice','location'], true) && count($options) < 2) throw new \InvalidArgumentException('Provide at least two choices.');
        if(is_array($expected)) {
            if(!array_is_list($expected)||count($expected)>30) throw new \InvalidArgumentException('Invalid accepted answers.');
            foreach($expected as $value) if(!is_string($value)||mb_strlen($value)>150) throw new \InvalidArgumentException('Invalid accepted answers.');
        } elseif(!is_scalar($expected)&&$expected!==null) throw new \InvalidArgumentException('Invalid accepted answer.');
        if ($mode !== 'review') {
            if ($type === 'experience_years' && (!is_numeric($expected) || $expected < 0 || $expected > 100)) throw new \InvalidArgumentException('Enter a number from 0 to 100.');
            if (in_array($type, ['boolean','document_required'], true) && !in_array($expected, ['yes','no'], true)) throw new \InvalidArgumentException('Select Yes or No.');
            if ($options) {
                $values = is_array($expected) ? $expected : [$expected];
                if (!$values || array_diff($values, $options)) throw new \InvalidArgumentException('Accepted answers must be configured choices.');
            }
            if ($op === 'in' && (!is_array($expected) || !$expected)) throw new \InvalidArgumentException('Select accepted answers.');
        }
        return ['label'=>$label,'help_text'=>mb_substr(trim((string)($c['help_text'] ?? '')),0,1000),'criterion_type'=>$type,'operator'=>$op,'expected'=>$expected,'options'=>$options,'decision_mode'=>$mode,'required'=>!empty($c['required']),'sort_order'=>max(0,min(999,(int)($c['sort_order']??0)))];
    }
    public static function answer(array $c, mixed $value): mixed
    {
        if ($value === null || $value === '' || $value === []) return null;
        $type = $c['criterion_type'];
        if ($type === 'experience_years') {
            if ((!is_string($value) && !is_int($value) && !is_float($value)) || !is_numeric($value) || $value < 0 || $value > 100) throw new \InvalidArgumentException('Enter years from 0 to 100.');
            return (float)$value;
        }
        if ($type === 'multiple_choice') {
            if (!is_array($value) || !array_is_list($value) || count($value)>30) throw new \InvalidArgumentException('Select valid choices.');
            foreach ($value as $v) if (!is_string($v) || !in_array($v,$c['options'],true)) throw new \InvalidArgumentException('Select valid choices.');
            return array_values(array_unique($value));
        }
        if (!is_string($value) || mb_strlen($value)>1000) throw new \InvalidArgumentException('Enter a short answer.');
        if (in_array($type,['boolean','document_required'],true) && !in_array($value,['yes','no'],true)) throw new \InvalidArgumentException('Select Yes or No.');
        if (in_array($type,['single_choice','education_level','location'],true) && !in_array($value,$c['options'],true)) throw new \InvalidArgumentException('Select a listed choice.');
        return trim($value);
    }
    public static function document(string $name, string $bytes): array
    {
        if (!in_array(strtolower(pathinfo($name,PATHINFO_EXTENSION)),['pdf','doc','docx'],true)) throw new \InvalidArgumentException('Upload a PDF, DOC or DOCX document.');
        $meta=Rules::file($name,$bytes,self::MAX_FILE);
        if ($bytes === '' || $meta['validation_status'] !== 'accepted') throw new \InvalidArgumentException('Document content or size is not accepted.');
        return $meta;
    }
    public static function origin(string $url): string
    {
        if (!preg_match('~^https://[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$~D',$url) || str_contains($url,'..')) throw new \InvalidArgumentException('Configure an HTTPS Careers origin without path or credentials.');
        return $url;
    }
    public static function open(array $v, ?string $today=null): bool
    {
        $today ??= gmdate('Y-m-d');
        return ($v['state']??'')==='published' && (empty($v['opens_on']) || $v['opens_on'] <= $today) && (empty($v['closes_on']) || $v['closes_on'] >= $today);
    }
}
