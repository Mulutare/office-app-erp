<?php

declare(strict_types=1);

$configuration = is_array($data['configuration'] ?? null)
    ? $data['configuration']
    : [];
$errors = (array) ($data['errors'] ?? []);
$status = $configuration['configuration_status'] ?? 'enabled_not_configured';
$statusLabel = match ($status) {
    'ready' => 'Ready',
    'configuration_invalid' => 'Configuration invalid',
    default => 'Enabled but not configured',
};
?>
<div class="module-stack analytics-workspace">
    <?php if (is_array($data['notice'] ?? null)): ?>
        <div class="alert alert-success">
            <?= e($data['notice']['message'] ?? '') ?>
        </div>
    <?php endif; ?>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endforeach; ?>

    <section class="card erp-record-header">
        <div>
            <p class="erp-eyebrow">Company administration</p>
            <h2>Analytics / Power BI</h2>
            <p>
                Manage the Power BI report mapping and authentication settings
                for the current company.
            </p>
        </div>
        <div class="erp-record-statuses">
            <span class="erp-status-badge erp-status-success">Licensed</span>
            <span class="erp-status-badge erp-status-<?= !empty($data['moduleEnabled']) ? 'success' : 'warning' ?>">
                <?= !empty($data['moduleEnabled']) ? 'Enabled' : 'Disabled' ?>
            </span>
            <span class="erp-status-badge erp-status-<?= $status === 'ready' ? 'success' : 'warning' ?>">
                <?= e($statusLabel) ?>
            </span>
        </div>
    </section>

    <section class="card erp-section-card">
        <header class="erp-section-header">
            <div>
                <p class="erp-eyebrow">Module availability</p>
                <h2>Enable Analytics</h2>
                <p>
                    Disabling Analytics hides navigation and blocks direct report
                    access while preserving this company's configuration.
                </p>
            </div>
        </header>
        <form
            method="post"
            action="<?= e(appBasePath() . '/administration/analytics/enable') ?>"
        >
            <?= csrfField() ?>
            <div class="analytics-toggle-row">
                <label class="erp-checkbox-field">
                    <input
                        type="checkbox"
                        name="enabled"
                        value="1"
                        <?= !empty($data['moduleEnabled']) ? 'checked' : '' ?>
                    >
                    <span>
                        <strong>Analytics enabled</strong>
                        <small>
                            Make Analytics available to authorized users in this company.
                        </small>
                    </span>
                </label>
                <button class="btn btn-primary btn-compact">
                    Save Module Setting
                </button>
            </div>
        </form>
    </section>

    <section class="card erp-section-card">
        <header class="erp-section-header">
            <div>
                <p class="erp-eyebrow">Company configuration</p>
                <h2>Power BI Configuration</h2>
                <p>
                    Client secrets are write-only. A blank secret preserves the
                    existing encrypted value.
                </p>
            </div>
        </header>
        <form
            method="post"
            action="<?= e(appBasePath() . '/administration/analytics') ?>"
        >
            <?= csrfField() ?>
            <div class="erp-form-grid erp-form-grid-three">
                <label class="form-field">
                    Authentication Mode
                    <select name="authentication_mode" required>
                        <?php foreach ([
                            'user_owns_data' => 'User authenticated',
                            'platform_managed' => 'Platform-managed service principal',
                            'company_managed' => 'Company-managed service principal',
                        ] as $value => $label): ?>
                            <option
                                value="<?= e($value) ?>"
                                <?= ($configuration['authentication_mode'] ?? 'user_owns_data') === $value ? 'selected' : '' ?>
                            ><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="form-field">
                    Microsoft / Entra Tenant ID
                    <input
                        name="microsoft_tenant_id"
                        value="<?= e($configuration['microsoft_tenant_id'] ?? '') ?>"
                        required
                    >
                </label>
                <label class="form-field">
                    Report Display Name
                    <input
                        name="report_name"
                        value="<?= e($configuration['report_name'] ?? '') ?>"
                        required
                    >
                </label>
                <label class="form-field">
                    Workspace ID <span class="erp-optional">If applicable</span>
                    <input
                        name="workspace_id"
                        value="<?= e($configuration['workspace_id'] ?? '') ?>"
                    >
                </label>
                <label class="form-field">
                    Report ID
                    <input
                        name="report_id"
                        value="<?= e($configuration['report_id'] ?? '') ?>"
                        required
                    >
                </label>
                <label class="form-field">
                    Dataset ID <span class="erp-optional">If applicable</span>
                    <input
                        name="dataset_id"
                        value="<?= e($configuration['dataset_id'] ?? '') ?>"
                    >
                </label>
                <label class="form-field">
                    Application / Client ID
                    <span class="erp-optional">Service principal only</span>
                    <input
                        name="client_id"
                        value="<?= e($configuration['client_id'] ?? '') ?>"
                    >
                </label>
                <label class="form-field">
                    Client Secret
                    <span class="erp-optional">
                        Secret Configured:
                        <?= !empty($configuration['secret_configured']) ? 'Yes' : 'No' ?>
                    </span>
                    <input
                        type="password"
                        name="client_secret"
                        value=""
                        autocomplete="new-password"
                    >
                </label>
                <label class="form-field">
                    Credential Reference
                    <span class="erp-optional">Optional vault reference</span>
                    <input
                        name="credential_reference"
                        value="<?= e($configuration['credential_reference'] ?? '') ?>"
                    >
                </label>
                <label class="erp-checkbox-field">
                    <input
                        type="checkbox"
                        name="enabled"
                        value="1"
                        <?= !isset($configuration['enabled']) || !empty($configuration['enabled']) ? 'checked' : '' ?>
                    >
                    <span>
                        <strong>Configuration enabled</strong>
                        <small>Allow this mapping to be used after validation.</small>
                    </span>
                </label>
            </div>
            <div class="erp-form-actions">
                <button class="btn btn-primary">Save Configuration</button>
            </div>
        </form>

        <form
            class="analytics-test-form"
            method="post"
            action="<?= e(appBasePath() . '/administration/analytics/validate') ?>"
        >
            <?= csrfField() ?>
            <button class="btn btn-secondary btn-compact">
                Validate Configuration
            </button>
            <span>
                Validates saved fields and readiness. It does not test live
                Microsoft connectivity.
            </span>
            <?php if (!empty($configuration['last_successful_validation_at'])): ?>
                <span>
                    Last successful validation:
                    <?= e($configuration['last_successful_validation_at']) ?>
                </span>
            <?php endif; ?>
        </form>
    </section>
</div>
