<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null)
    ? $data
    : [];

$users = is_array($data['users'] ?? null)
    ? $data['users']
    : [];

$filters = is_array(
    $data['filters'] ?? null
)
    ? $data['filters']
    : [];

$pagination = is_array(
    $data['pagination'] ?? null
)

    ? $data['pagination']
    : [];
    
$createdCredentials = is_array(
    $data['createdCredentials'] ?? null
)
    ? $data['createdCredentials']
    : null;
function userListUrl(
    array $filters,
    array $overrides = []
): string {
    $query = array_merge(
        $filters,
        $overrides
    );

    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }

    return appBasePath() . '/administration/users'
        . ($query === []
            ? ''
            : '?' . http_build_query($query));
}

function userStatusLabel(array $user): string
{
    if (!empty($user['is_locked'])) {
        return 'Locked';
    }

    return !empty($user['active'])
        ? 'Active'
        : 'Inactive';
}

function userStatusClass(array $user): string
{
    if (!empty($user['is_locked'])) {
        return 'badge-danger';
    }

    return !empty($user['active'])
        ? 'badge-success'
        : 'badge-muted';
}
?>
<?php if ($createdCredentials !== null): ?>
    <section
        class="alert alert-success credential-alert"
        role="status"
    >
        <div>
            <strong>User created successfully.</strong>

            <p>
                Give these credentials securely to the user.
                The temporary password is displayed only once.
            </p>
        </div>

        <dl class="credential-list">
            <div>
                <dt>Username</dt>
                <dd>
                    <?= e(
                        $createdCredentials['username']
                        ?? ''
                    ) ?>
                </dd>
            </div>

            <div>
                <dt>Temporary password</dt>
                <dd>
                    <code>
                        <?= e(
                            $createdCredentials[
                                'temporary_password'
                            ] ?? ''
                        ) ?>
                    </code>
                </dd>
            </div>
        </dl>

        <p class="credential-warning">
            Do not refresh or leave this page until the
            temporary password has been transferred securely.
        </p>
    </section>
<?php endif; ?>
<section class="administration-smart-list-toolbar">
    <div class="administration-smart-list-controls">
        <?php
        view(
            'administration.list-controls',
            [
                'listing' => $data['listing'],
                'entity' => 'users',
                'path' => appBasePath() . '/administration/users',
                'showPagination' => false,
            ]
        );
        ?>
    </div>

    <div class="administration-smart-list-primary-action">
        <a
            href="<?= e(appBasePath()) ?>/administration/users/create"
            class="btn btn-primary"
        >
            Create user
        </a>
    </div>
</section>

<section class="card table-card">
    <div class="table-summary">
        <strong>
            <?= e(
                $pagination['total'] ?? 0
            ) ?>
            users
        </strong>

        <span>
            Showing
            <?= e($pagination['from'] ?? 0) ?>
            –
            <?= e($pagination['to'] ?? 0) ?>
        </span>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Contact</th>
                    <th>Roles</th>
                    <th>Status</th>
                    <th>Last login</th>
                    <th>Created</th>
                    <th class="table-actions-column">
                        Actions
                    </th>
                </tr>
            </thead>

            <tbody>
            <?php if ($users === []): ?>
                <tr>
                    <td
                        colspan="7"
                        class="empty-state"
                    >
                        No users matched the filters.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td>
                            <strong>
                                <?= e(
                                    $user['display_name']
                                    ?? ''
                                ) ?>
                            </strong>

                            <small>
                                @<?= e(
                                    $user['username']
                                    ?? ''
                                ) ?>
                            </small>
                        </td>

                        <td>
                            <?= e(
                                $user['email'] ?? ''
                            ) ?>
                        </td>

                        <td>
                            <?php
                            $roles = is_array(
                                $user['roles'] ?? null
                            )
                                ? $user['roles']
                                : [];
                            ?>

                            <?php if ($roles === []): ?>
                                <span class="text-muted">
                                    No role
                                </span>
                            <?php else: ?>
                             <div class="role-badges">
    <?php foreach (
        array_slice($roles, 0, 2)
        as $role
    ): ?>
        <span class="badge badge-role">
            <?= e(
                ucwords(
                    str_replace('_', ' ', $role)
                )
            ) ?>
        </span>
    <?php endforeach; ?>

    <?php if (count($roles) > 2): ?>
        <span class="badge badge-muted">
            +<?= e(count($roles) - 2) ?> more
        </span>
    <?php endif; ?>
</div>
                            <?php endif; ?>
                        </td>

                        <td>
                            <span class="badge <?= e(
                                userStatusClass($user)
                            ) ?>">
                                <?= e(
                                    userStatusLabel($user)
                                ) ?>
                            </span>
                        </td>

                        <td>
                            <?= e(
                                $user['last_login_at']
                                ?? 'Never'
                            ) ?>
                        </td>

                        <td>
                            <?= e(
                                $user['created_at']
                                ?? ''
                            ) ?>
                        </td>

                        <td>
                            <a
                                href="<?= e(appBasePath()) ?>/administration/users/view?id=<?= e(
                                    $user['user_id']
                                ) ?>"
                                class="table-link"
                            >
                                View
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php view('components.list-pagination',['query'=>$data['listing']['list']['query'],'pagination'=>$pagination,'path'=>appBasePath().'/administration/users']); ?>
</section>
