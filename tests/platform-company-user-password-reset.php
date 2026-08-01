<?php

declare(strict_types=1);

use App\Services\PlatformCompanyUserPasswordResetService;

require_once __DIR__ . '/../app/helpers/bootstrap.php';

$fixture = db()->query(
    "SELECT
        admin.user_id AS admin_id,
        company.company_id,
        target.user_id AS target_id,
        target.password_hash,
        target.must_change_password,
        target.failed_login_count,
        target.locked_until,
        target.password_changed_at
     FROM users admin
     CROSS JOIN companies company
     INNER JOIN users target ON target.username = 'sample.employee'
     INNER JOIN company_users membership
        ON membership.company_id = company.company_id
       AND membership.user_id = target.user_id
     WHERE admin.username = 'admin'
       AND admin.is_platform_admin = TRUE
       AND company.code = 'sample-company'
     LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if (!is_array($fixture)) {
    fwrite(STDERR, 'FAIL Password-reset fixtures are unavailable.' . PHP_EOL);
    exit(1);
}

$service = new PlatformCompanyUserPasswordResetService();
$companyId = (int) $fixture['company_id'];
$targetId = (int) $fixture['target_id'];
$adminId = (int) $fixture['admin_id'];
$failures = 0;
$check = static function (bool $passed, string $message) use (&$failures): void {
    fwrite($passed ? STDOUT : STDERR, ($passed ? 'PASS ' : 'FAIL ') . $message . PHP_EOL);
    if (!$passed) {
        $failures++;
    }
};

try {
    $target = $service->target($companyId, $targetId, $adminId);
    $check(
        is_array($target)
        && ($target['targetUser']['username'] ?? null) === 'sample.employee',
        'Platform administrator can select a user inside a customer company'
    );

    $result = $service->reset($companyId, $targetId, $adminId);
    $check(!empty($result['successful']), 'Platform administrator resets the company user password');

    $state = db()->prepare(
        'SELECT password_hash, must_change_password, failed_login_count, locked_until
         FROM users WHERE user_id = :user_id'
    );
    $state->execute(['user_id' => $targetId]);
    $state = $state->fetch(PDO::FETCH_ASSOC);
    $check(
        is_array($state)
        && password_verify((string) $result['temporaryPassword'], (string) $state['password_hash'])
        && !empty($state['must_change_password'])
        && (int) $state['failed_login_count'] === 0
        && $state['locked_until'] === null,
        'Reset issues a valid one-time password and clears account locks'
    );

    $audit = db()->prepare(
        "SELECT new_values FROM audit_logs
         WHERE company_id = :company_id
           AND user_id = :admin_id
           AND action = 'RESET_COMPANY_USER_PASSWORD'
           AND record_id = :target_id
         ORDER BY audit_log_id DESC LIMIT 1"
    );
    $audit->execute([
        'company_id' => $companyId,
        'admin_id' => $adminId,
        'target_id' => (string) $targetId,
    ]);
    $auditJson = (string) $audit->fetchColumn();
    $check(
        $auditJson !== ''
        && !str_contains($auditJson, (string) $result['temporaryPassword']),
        'Reset is audited without storing the temporary password'
    );

    $rejected = $service->reset($companyId, $targetId, $targetId);
    $check(
        empty($rejected['successful']) && !empty($rejected['notFound']),
        'Non-platform users cannot use the vendor reset boundary'
    );
} finally {
    $restore = db()->prepare(
        'UPDATE users SET
            password_hash = :password_hash,
            must_change_password = :must_change_password,
            failed_login_count = :failed_login_count,
            locked_until = :locked_until,
            password_changed_at = :password_changed_at
         WHERE user_id = :user_id'
    );
    $restore->execute([
        'password_hash' => $fixture['password_hash'],
        'must_change_password' => $fixture['must_change_password'],
        'failed_login_count' => $fixture['failed_login_count'],
        'locked_until' => $fixture['locked_until'],
        'password_changed_at' => $fixture['password_changed_at'],
        'user_id' => $targetId,
    ]);
    db()->prepare(
        "DELETE FROM audit_logs
         WHERE company_id = :company_id
           AND user_id = :admin_id
           AND action = 'RESET_COMPANY_USER_PASSWORD'
           AND record_id = :target_id"
    )->execute([
        'company_id' => $companyId,
        'admin_id' => $adminId,
        'target_id' => (string) $targetId,
    ]);
}

fwrite(STDOUT, sprintf(
    "Platform company-user password reset: %d failure(s)%s",
    $failures,
    PHP_EOL
));
exit($failures === 0 ? 0 : 1);
