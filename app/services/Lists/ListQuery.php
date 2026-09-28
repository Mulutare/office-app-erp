<?php

declare(strict_types=1);

namespace App\Services\Lists;

/** Request values only. SQL identifiers always come from a module-owned whitelist. */
final class ListQuery
{
    public readonly string $q;
    public readonly int $page;
    public readonly int $perPage;
    public readonly string $sort;
    public readonly string $direction;
    public readonly array $filters;
    public readonly string $prefix;
    private array $context;

    public function __construct(array $input, array $sorts, string $defaultSort, array $filterKeys = [], string $defaultDirection = 'asc', string $prefix = '')
    {
        if ($prefix !== '' && !preg_match('/^[a-z][a-z_]*$/', $prefix)) throw new \InvalidArgumentException('Invalid list namespace.');
        $this->prefix = $prefix;
        $ownKeys=$prefix===''?array_merge($filterKeys,['q','search','page','per_page','sort','direction']):[$prefix];
        $this->context = array_diff_key($input,array_fill_keys(array_merge($ownKeys,['download','register','format','fields','selected','import_compatible']),true));
        if ($prefix !== '') $input = is_array($input[$prefix] ?? null) ? $input[$prefix] : [];
        $this->q = mb_substr(self::text($input['q'] ?? $input['search'] ?? ''), 0, 100);
        $this->page = max(1, min(1000000, (int) self::text($input['page'] ?? '1')));
        $size = (int) self::text($input['per_page'] ?? '25');
        $this->perPage = in_array($size, [25, 50, 100], true) ? $size : 25;
        $sort = self::text($input['sort'] ?? $defaultSort);
        $this->sort = array_key_exists($sort, $sorts) ? $sort : $defaultSort;
        $direction = strtolower(self::text($input['direction'] ?? $defaultDirection));
        $this->direction = in_array($direction, ['asc', 'desc'], true) ? $direction : $defaultDirection;
        $filters = [];
        foreach ($filterKeys as $key) {
            $filters[$key] = mb_substr(self::text($input[$key] ?? ''), 0, 100);
        }
        $this->filters = $filters;
    }

    public static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    public function parameters(array $overrides = []): array
    {
        return array_replace($this->filters, [
            'q' => $this->q, 'page' => $this->page, 'per_page' => $this->perPage,
            'sort' => $this->sort, 'direction' => $this->direction,
        ], $overrides);
    }

    public function url(string $path, array $overrides = [], array $actions = []): string
    {
        $parameters = $this->parameters($overrides);
        $parameters = array_replace($this->context, $this->prefix !== '' ? [$this->prefix => $parameters] : $parameters);
        $parameters = array_replace($parameters, $actions);
        return $path . '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    public function field(string $name): string
    {
        return $this->prefix === '' ? $name : $this->prefix . '[' . $name . ']';
    }

    /** Preserve the other registers when applying filters to a secondary table. */
    public function context(): array
    {
        return array_diff_key($this->context, [$this->prefix => true]);
    }

    public function resetUrl(string $path,array $hidden=[]): string
    {
        $parameters=array_replace($this->context(),$hidden);
        return $path.($parameters?'?'.http_build_query($parameters,'','&',PHP_QUERY_RFC3986):'');
    }

    public function pagination(int $total): array
    {
        $lastPage = max(1, (int) ceil($total / $this->perPage));
        $page = min($this->page, $lastPage);
        $offset = ($page - 1) * $this->perPage;
        return ['page' => $page, 'lastPage' => $lastPage, 'pageSize' => $this->perPage,
            'total' => $total, 'offset' => $offset, 'from' => $total ? $offset + 1 : 0,
            'to' => min($total, $offset + $this->perPage)];
    }
}
