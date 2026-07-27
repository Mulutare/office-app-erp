<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null)
    ? $data
    : [];
$company = is_array(
    $data['company'] ?? null
)
    ? $data['company']
    : [];
$modules = is_array(
    $data['modules'] ?? null
)
    ? $data['modules']
    : [];
$notice = is_string(
    $data['notice'] ?? null
)
    ? $data['notice']
    : null;
$enabledModuleCount = (int) (
    $data['enabledModuleCount'] ?? 0
);
$companyInitials = strtoupper(substr(
    (string) ($company['code'] ?? 'CO'),
    0,
    2
));
?>

<?php if ($notice !== null): ?>
    <div class="alert alert-success" role="status">
        <?= e($notice) ?>
    </div>
<?php endif; ?>

<div class="company-profile-actions">
    <a
        href="/office_app/public/administration/companies"
        class="btn btn-secondary"
    >
        Back to companies
    </a>
</div>

<section class="card company-profile-hero">
    <div class="company-profile-identity">
        <span
            class="company-profile-logo"
            style="--company-brand: <?= e(
                $company['brand_primary_color']
                ?? '#2563EB'
            ) ?>"
            aria-hidden="true"
        >
            <?= e($companyInitials) ?>
        </span>

        <div>
            <span class="module-eyebrow">
                Customer workspace
            </span>
            <h2>
                <?= e($company['name'] ?? '') ?>
            </h2>
            <p>
                <code>
                    <?= e(
                        $company['code'] ?? ''
                    ) ?>
                </code>
                <?php if (
                    !empty($company['legal_name'])
                ): ?>
                    <span aria-hidden="true">&bull;</span>
                    <?= e($company['legal_name']) ?>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <div class="company-profile-status">
        <span class="badge badge-<?= e(
            $company['statusTone'] ?? 'muted'
        ) ?>">
            <?= e(
                $company['statusLabel'] ?? ''
            ) ?>
        </span>
        <strong>
            <?= e($enabledModuleCount) ?>
            enabled modules
        </strong>
    </div>
</section>

<section class="company-profile-grid">
    <article class="card company-profile-panel">
        <h2>Company contact</h2>
        <dl class="company-profile-list">
            <div>
                <dt>Email</dt>
                <dd>
                    <?= e(
                        $company['contact_email']
                        ?? 'Not provided'
                    ) ?>
                </dd>
            </div>
            <div>
                <dt>Phone</dt>
                <dd>
                    <?= e(
                        $company['contact_phone']
                        ?? 'Not provided'
                    ) ?>
                </dd>
            </div>
            <div>
                <dt>Country</dt>
                <dd>
                    <?= e(
                        $company['country_code']
                        ?? ''
                    ) ?>
                </dd>
            </div>
        </dl>
    </article>

    <article class="card company-profile-panel">
        <h2>Workspace defaults</h2>
        <dl class="company-profile-list">
            <div>
                <dt>Currency</dt>
                <dd>
                    <?= e(
                        $company['default_currency']
                        ?? ''
                    ) ?>
                </dd>
            </div>
            <div>
                <dt>Timezone</dt>
                <dd>
                    <?= e(
                        $company['timezone'] ?? ''
                    ) ?>
                </dd>
            </div>
            <div>
                <dt>Brand color</dt>
                <dd class="company-color-value">
                    <span
                        style="--company-brand: <?= e(
                            $company[
                                'brand_primary_color'
                            ] ?? '#2563EB'
                        ) ?>"
                        aria-hidden="true"
                    ></span>
                    <?= e(
                        $company[
                            'brand_primary_color'
                        ] ?? ''
                    ) ?>
                </dd>
            </div>
        </dl>
    </article>

    <article class="card company-profile-panel">
        <h2>Subscription</h2>
        <dl class="company-profile-list">
            <div>
                <dt>Status</dt>
                <dd>
                    <?= e(
                        $company['statusLabel'] ?? ''
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
            <div>
                <dt>Provisioned by</dt>
                <dd>
                    <?= e(
                        $company[
                            'provisioned_by_name'
                        ] ?? 'System'
                    ) ?>
                </dd>
            </div>
        </dl>
    </article>
</section>

<section class="company-profile-modules">
    <div class="company-profile-section-heading">
        <div>
            <span class="module-eyebrow">
                Product entitlement
            </span>
            <h2>ERP module subscription</h2>
            <p>
                This profile is read-only in the current
                provisioning milestone.
            </p>
        </div>
        <span>
            <?= e($enabledModuleCount) ?>
            of <?= e(count($modules)) ?> enabled
        </span>
    </div>

    <div class="company-profile-module-grid">
        <?php foreach ($modules as $module): ?>
            <article class="card company-profile-module">
                <div>
                    <span
                        class="module-product-icon"
                        aria-hidden="true"
                    >
                        <?= e(
                            $module['icon_text']
                            ?? 'MD'
                        ) ?>
                    </span>
                    <span class="badge badge-<?= e(
                        $module['licenseTone']
                        ?? 'muted'
                    ) ?>">
                        <?= e(
                            $module['licenseLabel']
                            ?? ''
                        ) ?>
                    </span>
                </div>

                <h3>
                    <?= e($module['name'] ?? '') ?>
                </h3>
                <p>
                    <?= e(
                        $module['description'] ?? ''
                    ) ?>
                </p>

                <dl>
                    <div>
                        <dt>Licensed</dt>
                        <dd>
                            <?= e(
                                $module['licensed_at']
                                ?? '—'
                            ) ?>
                        </dd>
                    </div>
                    <div>
                        <dt>Expires</dt>
                        <dd>
                            <?= e(
                                $module['expires_at']
                                ?? 'No expiry'
                            ) ?>
                        </dd>
                    </div>
                </dl>
            </article>
        <?php endforeach; ?>
    </div>
</section>
