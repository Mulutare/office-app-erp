<?php

declare(strict_types=1);

$statistics = is_array($data['statistics'] ?? null)
    ? $data['statistics']
    : [];
?>

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

        <ul class="status-list">
            <li class="status-item">
                <span>Name</span>
                <strong>
                    <?= e(
                        $data['user']['display_name']
                        ?? ''
                    ) ?>
                </strong>
            </li>

            <li class="status-item">
                <span>Username</span>
                <strong>
                    <?= e(
                        $data['user']['username']
                        ?? ''
                    ) ?>
                </strong>
            </li>

            <li class="status-item">
                <span>Roles</span>
                <strong>
                    <?= e(
                        implode(
                            ', ',
                            $data['user']['roles']
                            ?? []
                        )
                    ) ?>
                </strong>
            </li>
        </ul>
    </article>
</section>
