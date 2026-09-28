<?php

declare(strict_types=1);

namespace App\Services\Lists;

use PDO;
use RuntimeException;

/** Executes module-owned SQL; the caller must supply the authorization-scoped base. */
final class SqlList
{
    private string $from;
    private array $parameters;
    private string $order;
    private string $scopeFrom;
    private array $scopeParameters;

    public function __construct(
        private PDO $connection,
        string $scopedSql,
        array $parameters,
        private ListQuery $query,
        array $searchColumns,
        array $sorts,
        string $primaryKey,
        array $filterColumns = [],
    ) {
        if ($parameters !== [] && array_is_list($parameters)) {
            // Existing domain queries use positional bindings. Normalize those before
            // adding named search/filter bindings; quoted SQL literals are untouched.
            $position = 0;
            $values = $parameters;
            $parameters = [];
            $scopedSql = preg_replace_callback("/'(?:[^'\\\\]|\\\\.|'')*'|\"(?:[^\"\\\\]|\\\\.|\"\")*\"|`[^`]*`|\\?/s",
                static function (array $match) use (&$position, &$parameters, $values): string {
                    if ($match[0] !== '?') return $match[0];
                    if (!array_key_exists($position, $values)) throw new RuntimeException('Missing list binding.');
                    $key = 'base_' . $position;
                    $parameters[$key] = $values[$position++];
                    return ':' . $key;
                }, $scopedSql);
            if ($position !== count($values)) throw new RuntimeException('Unexpected list binding.');
        }
        $this->scopeFrom=' FROM ('.$scopedSql.') listed';
        $this->scopeParameters=$parameters;
        $where = [];
        if ($query->q !== '') {
            $search = [];
            foreach ($searchColumns as $index => $column) {
                $key = 'list_search_' . $index;
                $search[] = $column . " LIKE :" . $key . " ESCAPE '!'";
                $parameters[$key] = '%' . strtr($query->q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            }
            if ($search !== []) $where[] = '(' . implode(' OR ', $search) . ')';
        }
        foreach ($filterColumns as $key => $column) {
            $value = $query->filters[$key] ?? '';
            if ($value === '') continue;
            $operator = '=';
            if (is_array($column)) {
                [$column, $operator] = $column;
                if (!in_array($operator, ['=', '>=', '<=', '>', '<'], true)) {
                    throw new RuntimeException('Invalid list filter configuration.');
                }
            }
            $where[] = $column . ' ' . $operator . ' :list_filter_' . $key;
            $parameters['list_filter_' . $key] = $value;
        }
        $this->from = ' FROM (' . $scopedSql . ') listed' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $this->parameters = $parameters;
        if (!isset($sorts[$query->sort])) throw new RuntimeException('Invalid list sort configuration.');
        $this->order = ' ORDER BY ' . $sorts[$query->sort] . ' ' . strtoupper($query->direction) . ', ' . $primaryKey . ' ASC';
    }

    public function page(): array
    {
        $count = $this->execute('SELECT COUNT(*)' . $this->from);
        $pagination = $this->query->pagination((int) $count->fetchColumn());
        $rows = $this->execute('SELECT *' . $this->from . $this->order . ' LIMIT :list_limit OFFSET :list_offset', [
            'list_limit' => $this->query->perPage, 'list_offset' => $pagination['offset'],
        ])->fetchAll(PDO::FETCH_ASSOC);
        return ['rows' => $rows, 'pagination' => $pagination, 'query' => $this->query];
    }

    /** Aggregate expressions are trusted module configuration, never request input. */
    public function aggregate(array $expressions): array
    {
        $columns = [];
        foreach ($expressions as $alias => $expression) {
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $alias)) {
                throw new RuntimeException('Invalid aggregate alias.');
            }
            $columns[] = $expression . ' AS `' . $alias . '`';
        }
        return $this->execute('SELECT ' . implode(', ', $columns) . $this->from)->fetch(PDO::FETCH_ASSOC);
    }

    /** Never silently truncate an export. Fetch at most one sentinel row above the cap. */
    public function export(int $maximum = 10000): array
    {
        $maximum = max(1, min($maximum, 10000));
        $rows = $this->execute('SELECT *' . $this->from . $this->order . ' LIMIT :list_limit', [
            'list_limit' => $maximum + 1,
        ])->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > $maximum) throw new RuntimeException('Export exceeds ' . $maximum . ' records. Narrow the filters and try again.');
        return $rows;
    }

    /** Trusted module expressions, over the whole authorized dataset before user filters. */
    public function options(string $column, ?string $label = null): array
    {
        $label ??= $column;
        $statement=$this->connection->prepare('SELECT DISTINCT '.$column.' option_value, '.$label.' option_label'.$this->scopeFrom.' ORDER BY option_label, option_value');
        // A correlated search expression may add bindings outside the scoped SELECT.
        foreach($this->scopeParameters as $key=>$value) {
            if(!preg_match('/(?<!:):'.preg_quote($key,'/').'\\b/',$this->scopeFrom))continue;
            $statement->bindValue(':'.$key,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
        }
        $statement->execute();$options=[];
        while($row=$statement->fetch(PDO::FETCH_ASSOC)) {
            if($row['option_value']===null || (string)$row['option_value']==='')continue;
            $options[(string)$row['option_value']]=(string)($row['option_label']??$row['option_value']);
        }
        return $options;
    }

    private function execute(string $sql, array $extra = []): \PDOStatement
    {
        $statement = $this->connection->prepare($sql);
        foreach ($this->parameters + $extra as $key => $value) {
            $statement->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->execute();
        return $statement;
    }
}
