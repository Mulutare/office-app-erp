<?php

declare(strict_types=1);
namespace App\Services;

/** Pure policy shared by session loading and database-backed authorization. */
final class EffectivePermissionPolicy
{
    public const MODULES = ['dashboard','sales','inventory','finance','hr','attendance','procurement','assets','analytics','administration','it','business'];

    public static function module(string $permission): ?string
    {
        $prefix = explode('.', $permission, 2)[0];
        $prefix = ['organization'=>'administration','audit'=>'administration','employee'=>'hr','leave'=>'hr'][$prefix] ?? $prefix;
        return in_array($prefix, self::MODULES, true) ? $prefix : null;
    }

    /** A role's child grants require that same role's gate; another role cannot activate dormant grants.
     * @param list<array{code:string,role_id:int|string}> $grants
     * @param list<string> $enabledModules
     * @return list<string>
     */
    public static function resolve(array $grants, array $enabledModules, array $overrides = []): array
    {
        $gates = [];
        foreach ($grants as $grant) $gates[(string)$grant['role_id']][$grant['code']] = true;
        $result = [];
        foreach ($grants as $grant) {
            $module = self::module(isset($grant['module']) ? $grant['module'].'.view' : $grant['code']);
            if ($module !== null && (!in_array($module, $enabledModules, true)
                || empty($gates[(string)$grant['role_id']][$module.'.module.enabled']))) continue;
            $result[$grant['code']] = true;
        }
        // User choices override role defaults; module availability remains the outer boundary.
        $modules = [];
        foreach ($grants as $grant) $modules[$grant['code']] = self::module(isset($grant['module']) ? $grant['module'].'.view' : $grant['code']);
        foreach ($overrides as $override) {
            $code = $override['code'];
            $modules[$code] = self::module(isset($override['module']) ? $override['module'].'.view' : $code);
            if ((bool)$override['allowed']) $result[$code] = true;
            else unset($result[$code]);
        }
        foreach (array_keys($result) as $code) {
            $module = $modules[$code] ?? self::module($code);
            if ($module !== null && (!in_array($module, $enabledModules, true) || !isset($result[$module.'.module.enabled']))) unset($result[$code]);
        }
        return array_keys($result);
    }
}
