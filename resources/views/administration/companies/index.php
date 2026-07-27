<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null)
    ? $data
    : [];
$companies = is_array(
    $data['companies'] ?? null
)
    ? $data['companies']
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
$notice = is_string(
    $data['notice'] ?? null
)
    ? $data['notice']
    : null;
$companyListUrl = static function (
    array $overrides = []
) use ($filters): string {
    $query = array_merge(
        $filters,
        $overrides
    );

    foreach ($query as $key => $value) {
        if (
            $value === ''
            || $value === null
            || $value === 'all'
        ) {
            unset($query[$key]);
        }
    }

    return '/office_app/public/administration/companies'
        . ($query === []
            ? ''
            : '?' . http_build_query($query));
};
?>

<?php if ($notice !== null): ?>
    <div class="alert alert-success" role="status">
        <?= e($notice) ?>
    </div>
<?php endif; ?>

<section class="company-directory-header">
    <div>
        <span class="module-eyebrow">
            Product administration
        </span>
        <h2>Customer workspace portfolio</h2>
        <p>
            Each company receives an independent ERP
            identity and a selected module subscription.
        </p>
    </div>

    <a
        href="/office_app/public/administration/companies/create"
        class="btn btn-primary"
    >
        Provision company
    </a>
</section>

<section class="card company-filter-card">
    <form
        method="get"
        action="/office_app/public/administration/companies"
        class="filter-form"
    >
        <div class="form-field">
            <label for="search">
                Search companies
            </label>
            <input
                id="search"
                name="search"
                type="search"
                value="<?= e(
                    $filters['search'] ?? ''
                ) ?>"
                placeholder="Name, code or contact email"
                maxlength="120"
            >
        </div>

        <div class="form-field">
            <label for="status">
                Subscription status
            </label>
            <select id="status" name="status">
                <?php
                $statuses = [
                    'all' => 'All companies',
                    'active' => 'Active',
                    'trial' => 'Trial',
                    'expired' => 'Expired',
                    'suspended' => 'Suspended',
                    'inactive' => 'Inactive',
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

        <div class="filter-actions">
            <button
                type="submit"
                class="btn btn-primary"
            >
                Apply filters
            </button>
            <a
                href="/office_app/public/administration/companies"
                class="btn btn-secondary"
            >
                Reset
            </a>
        </div>
    </form>

    <div class="company-result-count">
        <strong>
            <?= e(
                $pagination['total'] ?? 0
            ) ?>
        </strong>
        <span>customer companies</span>
    </div>
</section>

<?php if ($companies === []): ?>
    <section class="card company-empty-state">
        <span aria-hidden="true">CO</span>
        <h2>No companies found</h2>
        <p>
            Adjust the filters or provision the first
            customer workspace.
        </p>
    </section>
<?php else: ?>
    <section
        class="company-directory-grid"
        aria-label="Customer companies"
    >
        <?php foreach ($companies as $company): ?>
            <article class="card company-directory-card">
                <div class="company-directory-top">
                    <span
                        class="company-brand-swatch"
                        style="--company-brand: <?= e(
                            $company[
                                'brand_primary_color'
                            ] ?? '#2563EB'
                        ) ?>"
                        aria-hidden="true"
                    >
                        <?= e(strtoupper(substr(
                            (string) (
                                $company['code']
                                ?? 'CO'
                            ),
                            0,
                            2
                        ))) ?>
                    </span>

                    <span class="badge badge-<?= e(
                        $company['statusTone']
                        ?? 'muted'
                    ) ?>">
                        <?= e(
                            $company['statusLabel']
                            ?? ''
                        ) ?>
                    </span>
                </div>

                <div class="company-directory-copy">
                    <h3>
                        <?= e(
                            $company['name'] ?? ''
                        ) ?>
                    </h3>
                    <code>
                        <?= e(
                            $company['code'] ?? ''
                        ) ?>
                    </code>
                    <p>
                        <?= e(
                            $company['legal_name']
                            ?? $company[
                                'contact_email'
                            ]
                            ?? 'Customer company'
                        ) ?>
                    </p>
                </div>

                <dl class="company-directory-metrics">
                    <div>
                        <dt>Modules</dt>
                        <dd>
                            <?= e(
                                $company[
                                    'enabled_module_count'
                                ] ?? 0
                            ) ?>
                            enabled
                        </dd>
                    </div>
                    <div>
                        <dt>Currency</dt>
                        <dd>
                            <?= e(
                                $company[
                                    'default_currency'
                                ] ?? ''
                            ) ?>
                        </dd>
                    </div>
                    <div>
                        <dt>Timezone</dt>
                        <dd>
                            <?= e(
                                $company['timezone']
                                ?? ''
                            ) ?>
                        </dd>
                    </div>
                    <div>
                        <dt>Expires</dt>
                        <dd>
                            <?= e(
                                $company[
                                    'subscription_expires_at'
                                ] ?? 'No expiry'
                            ) ?>
                        </dd>
                    </div>
                </dl>

                <div class="company-directory-footer">
                    <span>
                        Created
                        <?= e(
                            $company['created_at']
                            ?? ''
                        ) ?>
                    </span>
                    <a
                        href="/office_app/public/administration/companies/view?id=<?= e(
                            $company['company_id']
                            ?? 0
                        ) ?>"
                        class="table-link"
                    >
                        View company
                    </a>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<?php if (
    ($pagination['lastPage'] ?? 1) > 1
): ?>
    <?php
    $page = (int) (
        $pagination['page'] ?? 1
    );
    $lastPage = (int) (
        $pagination['lastPage'] ?? 1
    );
    ?>
    <nav
        class="pagination company-pagination"
        aria-label="Company pagination"
    >
        <?php if ($page > 1): ?>
            <a
                class="pagination-link"
                href="<?= e($companyListUrl([
                    'page' => $page - 1,
                ])) ?>"
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
                href="<?= e($companyListUrl([
                    'page' => $page + 1,
                ])) ?>"
            >
                Next
            </a>
        <?php endif; ?>
    </nav>
<?php endif; ?>
