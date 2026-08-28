<?php

declare(strict_types=1);

$statistics = is_array($data['statistics'] ?? null)
    ? $data['statistics']
    : [];
$account = is_array($data['account'] ?? null) ? $data['account'] : [];
$formatDate = static function (mixed $value): string {
    $timestamp = is_string($value) ? strtotime($value) : false;
    return $timestamp === false ? '—' : date('d M Y, H:i', $timestamp);
};
$sessionSuccess = is_string($data['sessionSuccess'] ?? null)
    ? $data['sessionSuccess']
    : '';
$sessionError = is_string($data['sessionError'] ?? null)
    ? $data['sessionError']
    : '';
?>

<?php if ($sessionSuccess !== ''): ?>
    <div class="alert alert-success" role="status"><?= e($sessionSuccess) ?></div>
<?php endif; ?>
<?php if ($sessionError !== ''): ?>
    <div class="alert alert-danger" role="alert"><?= e($sessionError) ?></div>
<?php endif; ?>

<section class="card-grid">
    <article class="card">
        <p class="metric-label">
            Active users
        </p>

        <p class="metric-value">
            <?= e($statistics['users'] ?? 0) ?>
        </p>
    </article>

    <article class="card">
        <p class="metric-label">
            Successful logins today
        </p>

        <p class="metric-value">
            <?= e(
                $statistics['successfulLogins']
                ?? 0
            ) ?>
        </p>
    </article>

    <article class="card">
        <p class="metric-label">
            Failed logins today
        </p>

        <p class="metric-value">
            <?= e(
                $statistics['failedLogins']
                ?? 0
            ) ?>
        </p>
    </article>

    <article class="card">
        <p class="metric-label">
            Security alerts
        </p>

        <p class="metric-value">
            <?= e(
                $statistics['securityAlerts']
                ?? 0
            ) ?>
        </p>
    </article>
</section>

<section class="content-grid">
    <article class="card">
        <h2 class="card-title">
            System overview
        </h2>

        <ul class="status-list">
            <li class="status-item">
                <span>Application</span>
                <strong class="status-good">
                    Operational
                </strong>
            </li>

            <li class="status-item">
                <span>Database</span>
                <strong class="status-good">
                    Connected
                </strong>
            </li>

            <li class="status-item">
                <span>Authentication</span>
                <strong class="status-good">
                    Active
                </strong>
            </li>

            <li class="status-item">
                <span>Environment</span>
                <strong>
                    <?= e(
                        $data['environment']
                        ?? 'unknown'
                    ) ?>
                </strong>
            </li>
        </ul>
    </article>

    <article class="card dashboard-account-card">
        <h2 class="card-title">
            Signed-in account
        </h2>
        <dl class="dashboard-account-grid">
            <div class="dashboard-account-field"><dt class="dashboard-account-label">Name</dt><dd class="dashboard-account-value"><?=e($account['display_name']??'')?></dd></div>
            <div class="dashboard-account-field"><dt class="dashboard-account-label">Username</dt><dd class="dashboard-account-value"><?=e($account['username']??'')?></dd></div>
            <div class="dashboard-account-field dashboard-account-roles"><dt class="dashboard-account-label">Roles</dt><dd class="dashboard-account-value dashboard-role-list"><?php foreach(($account['roles']??[]) as $role):?><span class="dashboard-role-chip"><?=e(ucfirst(str_replace('_',' ',(string)$role)))?></span><?php endforeach;?></dd></div>
            <div class="dashboard-account-field"><dt class="dashboard-account-label">Active sessions</dt><dd class="dashboard-account-value dashboard-session-summary"><?=e($account['active_sessions']??0)?></dd></div>
            <div class="dashboard-account-field"><dt class="dashboard-account-label">Last login</dt><dd class="dashboard-account-value"><?=e($formatDate($account['last_login_at']??null))?></dd></div>
        </dl>
        <?php if (!empty($account['can_view_sessions'])): ?>
            <section class="dashboard-login-sessions" aria-labelledby="dashboard-login-sessions-title">
                <h3 id="dashboard-login-sessions-title">Login sessions</h3>
                <div class="dashboard-session-table-wrap">
                    <table class="dashboard-session-table">
                        <thead><tr><th>Session</th><th>Signed in</th><th>Last activity</th><th>IP address</th><th>Device / Browser</th><th>Status</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach (($account['sessions'] ?? []) as $session): ?>
                            <tr>
                                <td><?php if (!empty($session['current'])): ?><span class="dashboard-current-session">Current</span><?php else: ?>Other<?php endif; ?></td>
                                <td><?= e($formatDate($session['signed_in_at'] ?? null)) ?></td>
                                <td><?= e($formatDate($session['last_activity_at'] ?? null)) ?></td>
                                <td><?= e($session['ip_address'] ?? '') ?></td>
                                <td><?= e($session['device'] ?? 'Unknown device') ?></td>
                                <td><span class="dashboard-session-active"><?= e($session['status'] ?? 'Active') ?></span></td>
                                <td>
                                    <?php if (empty($session['current'])): ?>
                                        <form method="post" action="<?= e(appBasePath()) ?>/dashboard/sessions/<?= e($session['authenticated_user_session_id'] ?? '') ?>/terminate" onsubmit="return confirm('Terminate this login session?');">
                                            <?= csrfField() ?>
                                            <button type="submit" class="btn btn-danger-outline btn-compact">Terminate</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <form class="session-bulk-action" method="post" action="<?= e(appBasePath()) ?>/dashboard/sessions/terminate-others" onsubmit="return confirm('Terminate all other login sessions for this account? Your current session will remain active.');">
                    <?= csrfField() ?>
                    <button type="submit" class="btn btn-danger-outline">Terminate all other sessions</button>
                    <span>Your current session will remain active.</span>
                </form>
            </section>
        <?php endif; ?>
    </article>
</section>
