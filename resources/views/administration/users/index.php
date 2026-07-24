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

    return '/office_app/public/administration/users'
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

<section class="toolbar">
    <form
        method="get"
        action="/office_app/public/administration/users"
        class="filter-form"
    >
        <div class="form-field">
            <label for="search">
                Search users
            </label>

            <input
                id="search"
                name="search"
                type="search"
                value="<?= e(
                    $filters['search'] ?? ''
                ) ?>"
                placeholder="Name, username or email"
                maxlength="100"
            >
        </div>

        <div class="form-field">
            <label for="status">
                Account status
            </label>

            <select
                id="status"
                name="status"
            >
                <?php
                $statuses = [
                    'all' => 'All accounts',
                    'active' => 'Active',
                    'inactive' => 'Inactive',
                    'locked' => 'Locked',
                ];
                ?>

                <?php foreach (
                    $statuses as $value => $label
                ): ?>
                    <option
                        value="<?= e($value) ?>"
                        <?= (
                            $filters['status']
                            ?? 'all'
                        ) === $value
                            ? 'selected'
                            : '' ?>
                    >
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <input
            type="hidden"
            name="sort"
            value="<?= e(
                $filters['sort']
                ?? 'created_at'
            ) ?>"
        >

        <input
            type="hidden"
            name="direction"
            value="<?= e(
                $filters['direction']
                ?? 'desc'
            ) ?>"
        >

        <div class="filter-actions">
            <button
                type="submit"
                class="btn btn-primary"
            >
                Apply filters
            </button>

            <a
                href="/office_app/public/administration/users"
                class="btn btn-secondary"
            >
                Reset
            </a>
        </div>
    </form>

    <a
        href="/office_app/public/administration/users/create"
        class="btn btn-primary"
    >
        Create user
    </a>
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
                                <?= e(
                                    implode(', ', $roles)
                                ) ?>
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
                                href="/office_app/public/administration/users/<?= e(
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

    <?php if (
        ($pagination['lastPage'] ?? 1) > 1
    ): ?>
        <nav
            class="pagination"
            aria-label="User pagination"
        >
            <?php
            $page = (int) (
                $pagination['page'] ?? 1
            );

            $lastPage = (int) (
                $pagination['lastPage'] ?? 1
            );
            ?>

            <?php if ($page > 1): ?>
                <a
                    class="pagination-link"
                    href="<?= e(
                        userListUrl(
                            $filters,
                            [
                                'page' => $page - 1,
                            ]
                        )
                    ) ?>"
                >
                    Previous
                </a>
            <?php endif; ?>

            <span class="pagination-status">
                Page <?= e($page) ?>
                of <?= e($lastPage) ?>
            </span>

            <?php if ($page < $lastPage): ?>
                <a
                    class="pagination-link"
                    href="<?= e(
                        userListUrl(
                            $filters,
                            [
                                'page' => $page + 1,
                            ]
                        )
                    ) ?>"
                >
                    Next
                </a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</section>