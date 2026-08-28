<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helpers/bootstrap.php';

use App\Repositories\RepositoryFactory;
use App\Services\AuthenticatedSessionService;

$checks = 0;
$failures = 0;
$results = [];
$check = static function (bool $condition, string $description) use (&$checks, &$failures, &$results): void {
    $checks++;
    if (!$condition) {
        $failures++;
    }
    $results[] = [$condition, $description];
};

$companyIds = db()->query(
    'SELECT company_id FROM companies ORDER BY company_id LIMIT 2'
)->fetchAll(PDO::FETCH_COLUMN);
$userIds = db()->query(
    'SELECT user_id FROM users ORDER BY user_id LIMIT 2'
)->fetchAll(PDO::FETCH_COLUMN);

if (count($companyIds) < 2 || count($userIds) < 2) {
    throw new RuntimeException('Session termination fixtures require two companies and two users.');
}

[$companyId, $otherCompanyId] = array_map('intval', $companyIds);
[$userId, $otherUserId] = array_map('intval', $userIds);
$repository = RepositoryFactory::authenticatedSessions();
$service = new AuthenticatedSessionService($repository);
$now = time();
$format = static fn (int $timestamp): string => date('Y-m-d H:i:s', $timestamp);
$register = static function (
    int $company,
    int $user,
    string $hash,
    int $expires
) use ($repository, $now, $format): int {
    return $repository->register([
        'company_id' => $company,
        'user_id' => $user,
        'session_hash' => $hash,
        'signed_in_at' => $format($now - 60),
        'last_activity_at' => $format($now - 30),
        'expires_at' => $format($expires),
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Session termination test',
    ]);
};

$prefix = bin2hex(random_bytes(8));
$currentHash = hash('sha256', session_id());
$currentId = $register($companyId, $userId, $currentHash, $now + 3600);
$otherId = $register($companyId, $userId, hash('sha256', $prefix . '-other'), $now + 3600);
$secondOtherId = $register($companyId, $userId, hash('sha256', $prefix . '-other-2'), $now + 3600);
$unrelatedId = $register($companyId, $otherUserId, hash('sha256', $prefix . '-unrelated'), $now + 3600);
$crossCompanyId = $register($otherCompanyId, $userId, hash('sha256', $prefix . '-cross'), $now + 3600);
$_SESSION['auth'] = [
    'user_id' => $userId,
    'company' => ['company_id' => $companyId],
    'authenticated_session_registry_id' => $currentId,
    'authenticated_at' => $now,
];

$check($service->count($companyId, $userId) === 3, 'Active session count includes current and other sessions');
$check($service->terminateSession($companyId, $userId, $otherId), 'Administrator terminates one other active session');
$check(!$service->terminateSession($companyId, $userId, $otherId), 'Repeated individual termination is idempotent');
$check(!$service->terminateSession($companyId, $userId, $currentId), 'Current session cannot be terminated through individual workflow');
$check(!$service->terminateSession($companyId, $userId, $crossCompanyId), 'Cross-company session identifier cannot be terminated');
$check($service->count($companyId, $userId) === 2, 'Individual termination decreases active count');

$terminatedOthers = $service->terminateOtherSessions($companyId, $userId);
$check($terminatedOthers === 1, 'Terminate-other-sessions returns an exact count');
$check($service->count($companyId, $userId) === 1, 'Terminate-other-sessions preserves current session');
$check($repository->findByHash($companyId, $otherUserId, hash('sha256', $prefix . '-unrelated')) !== null, 'Unrelated user session remains unchanged');
$check($repository->findByHash($otherCompanyId, $userId, hash('sha256', $prefix . '-cross'))['revoked_at'] === null, 'Unrelated company session remains unchanged');

$terminatedAll = $service->terminateAllSessions($companyId, $otherUserId);
$check($terminatedAll === 1, 'Terminate-all revokes every active session for another account');
$check($service->terminateAllSessions($companyId, $userId) === 0, 'Own-account terminate-all preserves the current session');

$listed = $service->list($companyId, $userId);
$check(!str_contains(json_encode($listed) ?: '', 'session_hash'), 'Session DTO never exposes the raw session hash');

$repository->revokeById($companyId, $userId, $currentId, $format($now));
$check(!$service->touchOrRegister($companyId, $userId), 'Explicitly revoked registered session fails validation');
$check($repository->findByHash($companyId, $userId, $currentHash)['revoked_at'] !== null, 'Revoked session cannot recreate itself through touchOrRegister');

unset($_SESSION['auth']['authenticated_session_registry_id']);
session_regenerate_id(true);
$expiredHash = hash('sha256', session_id());
$expiredId = $register($companyId, $userId, $expiredHash, $now - 1);
$_SESSION['auth']['authenticated_session_registry_id'] = $expiredId;
$check(!$service->touchOrRegister($companyId, $userId), 'Expired registered session fails validation');

unset($_SESSION['auth']['authenticated_session_registry_id']);
session_regenerate_id(true);
$service->register($companyId, $userId, $now);
$check($service->touchOrRegister($companyId, $userId), 'Fresh login after termination creates a valid registered session');

$routes = file_get_contents(__DIR__ . '/../routes/web.php') ?: '';
$controller = file_get_contents(__DIR__ . '/../app/controllers/AuthenticatedSessionController.php') ?: '';
$dashboard = file_get_contents(__DIR__ . '/../resources/views/dashboard/index.php') ?: '';
$adminView = file_get_contents(__DIR__ . '/../resources/views/administration/users/show.php') ?: '';
$check(str_contains($routes, "->post(\n    '/dashboard/sessions") && !str_contains($routes, "->get(\n    '/dashboard/sessions"), 'Session mutations are POST-only');
$check(str_contains($controller, "'administration.users.manage'") && str_contains($controller, 'verifyCsrfToken'), 'Termination actions require manage permission and CSRF');
$check(!str_contains($dashboard . $adminView, 'session_hash'), 'Rendered session forms contain no session hash');
$check(str_contains($controller, 'authenticated_session.terminated') && !str_contains($controller, "'session_hash'"), 'Audit events are emitted without session hash metadata');

foreach ($results as [$passed, $description]) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $description . PHP_EOL;
}
echo PHP_EOL . $checks . ' checks, ' . $failures . ' failures' . PHP_EOL;
exit($failures === 0 ? 0 : 1);
