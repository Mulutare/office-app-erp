<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = 0;
$checks = 0;
$check = static function (bool $condition, string $description) use (&$failures, &$checks): void {
    $checks++;
    fwrite($condition ? STDOUT : STDERR, ($condition ? 'PASS ' : 'FAIL ') . $description . PHP_EOL);
    $failures += $condition ? 0 : 1;
};
$source = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$routes = $source('routes/web.php');
$controller = $source('app/controllers/AssetController.php');
$views = $source('resources/views/assets/index.php') . $source('resources/views/assets/show.php');
$staticHelper = $source('app/helpers/view.php');

$businessRoutes = [
    '/assets-management',
    '/assets-management/categories',
    '/assets-management/capitalize',
    '/assets-management/{id}',
    '/assets-management/{id}/activate',
    '/assets-management/{id}/depreciation/{lineId}/post',
    '/assets-management/{id}/transfer',
    '/assets-management/{id}/maintenance',
    '/assets-management/{id}/dispose',
];
foreach ($businessRoutes as $route) {
    $check(str_contains($routes, "'{$route}'"), 'Router contains ' . $route);
}
$check(!preg_match("#['\"]\/assets(?:['\"]|\/)#", $routes), 'Router has no Fixed Assets route under the static namespace');
$check(!preg_match("#['\"]\/assets(?:['\"]|\/)#", $controller), 'Controller redirects use only the new business namespace');
$check(!str_contains($views, '/office_app/public/assets/'), 'Assets links and forms use only the new business namespace');
$check(str_contains($controller, "requireModule('assets')") && str_contains($controller, 'requireTenantPermission($permission)'), 'Assets entitlement and assets.* permission authorization is unchanged');
$check(str_contains($staticHelper, "appUrl('/assets/')"), 'Static resource URL generation remains under /assets/');

echo sprintf("%d Assets route checks, %d failures\n", $checks, $failures);
exit($failures === 0 ? 0 : 1);
