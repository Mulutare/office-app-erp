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
$ownerCredentials = is_array(
    $data['ownerCredentials'] ?? null
)
    ? $data['ownerCredentials']
    : null;
$approvalErrors = is_array(
    $data['approvalErrors'] ?? null
)
    ? $data['approvalErrors']
    : [];
$lifecycleErrors = is_array(
    $data['lifecycleErrors'] ?? null
)
    ? $data['lifecycleErrors']
    : [];
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

<?php if ($ownerCredentials !== null): ?>
    <section
        class="alert alert-success credential-alert"
        role="status"
    >
        <strong>
            Company owner credentials
        </strong>
        <p>
            Transfer these credentials securely. The
            temporary password is displayed only once.
        </p>
        <dl class="credential-list">
            <div>
                <dt>Username</dt>
                <dd>
                    <?= e(
                        $ownerCredentials['username']
                        ?? ''
                    ) ?>
                </dd>
            </div>
            <div>
                <dt>Temporary password</dt>
                <dd>
                    <code><?= e(
                        $ownerCredentials[
                            'temporary_password'
                        ] ?? ''
                    ) ?></code>
                </dd>
            </div>
        </dl>
        <p class="credential-warning">
            The owner cannot sign in until the vendor
            approves this company.
        </p>
    </section>
<?php endif; ?>

<?php if (!empty($approvalErrors['form'])): ?>
    <div class="alert alert-danger" role="alert">
        <?= e($approvalErrors['form']) ?>
    </div>
<?php endif; ?>

<?php if ($lifecycleErrors !== []): ?>
    <div class="alert alert-danger" role="alert">
        <?= e(
            $lifecycleErrors['form']
            ?? $lifecycleErrors['reason']
            ?? 'The lifecycle action could not be completed.'
        ) ?>
    </div>
<?php endif; ?>

<div class="company-profile-actions">
    <a
        href="/office_app/public/administration/companies"
        class="btn btn-secondary"
    >
        Back to companies
    </a>

    <a
        href="/office_app/public/administration/companies/edit?id=<?= e(
            $company['company_id'] ?? 0
        ) ?>"
        class="btn btn-secondary"
    >
        Edit company
    </a>

    <?php if (
        ($company['approval_status'] ?? '')
        === 'pending'
    ): ?>
        <form
            method="post"
            action="/office_app/public/administration/companies/approve"
        >
            <?= csrfField() ?>
            <input
                type="hidden"
                name="company_id"
                value="<?= e(
                    $company['company_id'] ?? 0
                ) ?>"
            >
            <button
                type="submit"
                class="btn btn-primary"
            >
                Approve and activate
            </button>
        </form>
    <?php endif; ?>
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

<?php if (
    ($company['code'] ?? '') !== 'default'
    && ($company['approval_status'] ?? '')
        === 'approved'
): ?>
    <section class="card company-lifecycle-card">
        <div class="company-lifecycle-copy">
            <span class="module-eyebrow">
                Vendor control
            </span>
            <?php if (
                ($company['subscription_status'] ?? '')
                === 'suspended'
            ): ?>
                <h2>Reactivate company access</h2>
                <p>
                    Reopening the workspace allows active
                    company members to sign in again. The
                    subscription resumes as
                    <strong><?= e(
                        ucfirst((string) (
                            $company[
                                'commercialStatus'
                            ] ?? 'active'
                        ))
                    ) ?></strong>.
                </p>
            <?php else: ?>
                <h2>Suspend company access</h2>
                <p>
                    Suspension blocks new sign-ins and invalidates
                    existing customer sessions on their next
                    protected request. Data and configuration are
                    preserved.
                </p>
            <?php endif; ?>
        </div>

        <?php if (
            ($company['subscription_status'] ?? '')
            === 'suspended'
        ): ?>
            <form
                method="post"
                action="/office_app/public/administration/companies/lifecycle"
                class="company-lifecycle-form"
            >
                <?= csrfField() ?>
                <input
                    type="hidden"
                    name="company_id"
                    value="<?= e(
                        $company['company_id'] ?? 0
                    ) ?>"
                >
                <input
                    type="hidden"
                    name="action"
                    value="reactivate"
                >
                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Reactivate access
                </button>
            </form>
        <?php else: ?>
            <form
                method="post"
                action="/office_app/public/administration/companies/lifecycle"
                class="company-lifecycle-form"
            >
                <?= csrfField() ?>
                <input
                    type="hidden"
                    name="company_id"
                    value="<?= e(
                        $company['company_id'] ?? 0
                    ) ?>"
                >
                <input
                    type="hidden"
                    name="action"
                    value="suspend"
                >
                <div class="form-field">
                    <label for="suspension_reason">
                        Suspension reason
                    </label>
                    <textarea
                        id="suspension_reason"
                        name="reason"
                        rows="3"
                        maxlength="500"
                        placeholder="Record the commercial, compliance or support reason."
                        required
                    ></textarea>
                    <small class="field-help">
                        Required, 10-500 characters. Recorded
                        in the audit log.
                    </small>
                </div>
                <button
                    type="submit"
                    class="btn btn-danger"
                >
                    Suspend company
                </button>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>

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
        <h2>Company owner</h2>
        <dl class="company-profile-list">
            <div>
                <dt>Name</dt>
                <dd>
                    <?= e(
                        $company['owner_name']
                        ?? 'Not assigned'
                    ) ?>
                </dd>
            </div>
            <div>
                <dt>Username</dt>
                <dd>
                    <?= e(
                        $company['owner_username']
                        ?? 'Not assigned'
                    ) ?>
                </dd>
            </div>
            <div>
                <dt>Email</dt>
                <dd>
                    <?= e(
                        $company['owner_email']
                        ?? 'Not assigned'
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
            <div>
                <dt>Approved by</dt>
                <dd>
                    <?= e(
                        $company['approved_by_name']
                        ?? 'Pending vendor approval'
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
                These utilities were licensed by the vendor.
                They become available only after approval
                and user permission assignment.
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
