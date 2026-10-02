<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = 0;
$checks = 0;

$check = static function (bool $ok, string $label) use (&$failures, &$checks): void {
    $checks++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) $failures++;
};

$auth = file_get_contents($root . '/app/services/AuthService.php');
$companies = file_get_contents($root . '/app/controllers/CompanyAdministrationController.php');
$companyView = file_get_contents($root . '/resources/views/administration/companies/show.php');
$reset = file_get_contents($root . '/app/services/PlatformCompanyUserPasswordResetService.php');

$check(
    str_contains($auth, 'entitledModules($_SESSION['."'auth'".']['."'modules'".'], $_SESSION['."'auth'".']['."'permissions'".'])'),
    'Company context resolves enabled modules from effective permissions'
);

$createStart = strpos($companies, 'public function create(): void');
$storeStart = strpos($companies, 'public function store(): void');
$createSource = substr($companies, $createStart, $storeStart - $createStart);

$check(
    !str_contains($createSource, '$details['."'modules'".']')
    && !str_contains($createSource, '$companyModules'),
    'Platform company-create page has no undefined company-details dependency'
);

$check(
    str_contains($companyView, "empty(\$companyUser['is_platform_admin'])")
    && str_contains($companyView, 'Reset password')
    && str_contains($companyView, 'Platform-managed'),
    'Company user list hides tenant password reset for platform administrators'
);

$check(
    str_contains($reset, "!empty(\$user['is_platform_admin'])"),
    'Password-reset backend continues rejecting platform-administrator targets'
);

echo "$checks platform regression checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
