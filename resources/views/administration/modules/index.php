<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
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
$notice = is_array(
    $data['notice'] ?? null
)
    ? $data['notice']
    : null;
$errors = is_array(
    $data['errors'] ?? null
)
    ? $data['errors']
    : [];
$releasedCount = 0;
$enabledCount = 0;

foreach ($modules as $module) {
    if (($module['release_status'] ?? 'roadmap') === 'released') {
        $releasedCount++;
    }

    if (!empty($module['isEnabled'])) {
        $enabledCount++;
    }
}
?>

<?php if ($notice !== null): ?>
    <div class="alert alert-success" role="status">
        <?= e($notice['message'] ?? '') ?>
    </div>
<?php endif; ?>

<?php if (!empty($errors['form'])): ?>
    <div class="alert alert-danger" role="alert">
        <?= e($errors['form']) ?>
    </div>
<?php endif; ?>

<?php if (!empty($errors['modules'])): ?>
    <div class="alert alert-danger" role="alert">
        <?= e($errors['modules']) ?>
    </div>
<?php endif; ?>

<section class="card module-company-summary">
    <div>
        <span class="module-eyebrow">
            Configured company
        </span>
        <h2>
            <?= e(
                $company['name']
                ?? 'Company'
            ) ?>
        </h2>
        <p>
            Company code:
            <code>
                <?= e($company['code'] ?? '') ?>
            </code>
        </p>
    </div>

    <dl class="module-company-metrics">
        <div>
            <dt>Enabled</dt>
            <dd><?= e($enabledCount) ?></dd>
        </div>
        <div>
            <dt>Released</dt>
            <dd><?= e($releasedCount) ?></dd>
        </div>
        <div>
            <dt>Product catalog</dt>
            <dd><?= e(count($modules)) ?></dd>
        </div>
    </dl>
</section>

<form
    method="post"
    action="/office_app/public/administration/modules"
    class="module-entitlement-form"
>
    <?= csrfField() ?>

    <div class="module-catalog-heading">
        <div>
            <h2>ERP module catalog</h2>
            <p>
                Released and licensed modules can be
                enabled independently for this company.
            </p>
        </div>

        <button
            type="submit"
            class="btn btn-primary"
        >
            Save module settings
        </button>
    </div>

    <section
        class="module-entitlement-grid"
        aria-label="ERP module entitlements"
    >
        <?php foreach ($modules as $module): ?>
            <?php
            $canToggle = !empty(
                $module['canToggle']
            );
            $isEnabled = !empty(
                $module['isEnabled']
            );
            ?>

            <article class="card module-entitlement-card">
                <div class="module-entitlement-top">
                    <span
                        class="module-product-icon"
                        aria-hidden="true"
                    >
                        <?= e(
                            $module['icon_text']
                            ?? 'MD'
                        ) ?>
                    </span>

                    <div class="module-statuses">
                        <span class="badge badge-<?= e(
                            $module[
                                'availabilityTone'
                            ] ?? 'muted'
                        ) ?>">
                            <?= e(
                                $module[
                                    'availabilityLabel'
                                ] ?? ''
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
                </div>

                <div class="module-entitlement-copy">
                    <h3>
                        <?= e($module['name'] ?? '') ?>
                    </h3>
                    <p>
                        <?= e(
                            $module['description'] ?? ''
                        ) ?>
                    </p>
                </div>

                <div class="module-entitlement-meta">
                    <span>
                        Route
                    </span>
                    <code>
                        <?= e(
                            $module['route_path'] ?? ''
                        ) ?>
                    </code>
                </div>

                <dl class="module-company-metrics">
                    <div><dt>Release</dt><dd><?= e($module['availabilityLabel'] ?? '') ?></dd></div>
                    <div><dt>License</dt><dd><?= e($module['licenseLabel'] ?? '') ?></dd></div>
                    <div><dt>Company</dt><dd><?= e($module['companyLabel'] ?? '') ?></dd></div>
                </dl>

                <label class="module-toggle">
                    <input
                        type="checkbox"
                        name="module_codes[]"
                        value="<?= e(
                            $module['code'] ?? ''
                        ) ?>"
                        <?= $isEnabled
                            ? 'checked'
                            : '' ?>
                        <?= !$canToggle
                            ? 'disabled'
                            : '' ?>
                    >
                    <span>
                        <strong>
                            <?= $isEnabled
                                ? 'Enabled'
                                : 'Disabled' ?>
                        </strong>
                        <small>
                            <?= e($module['stateExplanation'] ?? '') ?>
                        </small>
                    </span>
                </label>
            </article>
        <?php endforeach; ?>
    </section>

    <div class="module-form-footer">
        <p>
            Disabling a module hides its navigation and
            blocks direct access. Existing data is preserved.
        </p>

        <button
            type="submit"
            class="btn btn-primary"
        >
            Save module settings
        </button>
    </div>
</form>
