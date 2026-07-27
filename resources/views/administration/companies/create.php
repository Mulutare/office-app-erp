<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null)
    ? $data
    : [];
$modules = is_array(
    $data['modules'] ?? null
)
    ? $data['modules']
    : [];
$timezones = is_array(
    $data['timezones'] ?? null
)
    ? $data['timezones']
    : [];
$currencies = is_array(
    $data['currencies'] ?? null
)
    ? $data['currencies']
    : [];
$errors = is_array(
    $data['errors'] ?? null
)
    ? $data['errors']
    : [];
$old = is_array($data['old'] ?? null)
    ? $data['old']
    : [];
$selectedModules = is_array(
    $old['module_codes'] ?? null
)
    ? array_values(array_filter(
        $old['module_codes'],
        'is_string'
    ))
    : [];
$selectedTimezone = (string) (
    $old['timezone'] ?? 'Africa/Nairobi'
);
$selectedCurrency = (string) (
    $old['default_currency'] ?? 'KES'
);
$subscriptionStatus = (string) (
    $old['subscription_status'] ?? 'active'
);
$brandColor = (string) (
    $old['brand_primary_color']
    ?? '#2563EB'
);

if (
    !preg_match(
        '/^#[0-9A-Fa-f]{6}$/',
        $brandColor
    )
) {
    $brandColor = '#2563EB';
}
?>

<?php if (!empty($errors['form'])): ?>
    <div class="alert alert-danger" role="alert">
        <?= e($errors['form']) ?>
    </div>
<?php endif; ?>

<form
    method="post"
    action="/office_app/public/administration/companies"
    class="company-provisioning-form"
>
    <?= csrfField() ?>

    <div class="company-provisioning-layout">
        <div class="company-provisioning-main">
            <section class="card company-form-section">
                <div class="company-form-heading">
                    <span>01</span>
                    <div>
                        <h2>Company identity</h2>
                        <p>
                            Define the customer workspace
                            and primary contact details.
                        </p>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-field">
                        <label for="name">
                            Company name
                        </label>
                        <input
                            id="name"
                            name="name"
                            type="text"
                            value="<?= e(
                                $old['name'] ?? ''
                            ) ?>"
                            maxlength="150"
                            placeholder="Example: Acme Holdings"
                            required
                            autofocus
                        >
                        <?php if (
                            !empty($errors['name'])
                        ): ?>
                            <small class="field-error">
                                <?= e($errors['name']) ?>
                            </small>
                        <?php endif; ?>
                    </div>

                    <div class="form-field">
                        <label for="code">
                            Workspace code
                        </label>
                        <input
                            id="code"
                            name="code"
                            type="text"
                            value="<?= e(
                                $old['code'] ?? ''
                            ) ?>"
                            maxlength="50"
                            placeholder="acme-holdings"
                            autocomplete="off"
                            required
                        >
                        <small class="field-help">
                            Permanent lowercase identifier.
                        </small>
                        <?php if (
                            !empty($errors['code'])
                        ): ?>
                            <small class="field-error">
                                <?= e($errors['code']) ?>
                            </small>
                        <?php endif; ?>
                    </div>

                    <div class="form-field form-field-wide">
                        <label for="legal_name">
                            Legal name
                            <span>Optional</span>
                        </label>
                        <input
                            id="legal_name"
                            name="legal_name"
                            type="text"
                            value="<?= e(
                                $old['legal_name'] ?? ''
                            ) ?>"
                            maxlength="190"
                            placeholder="Registered legal entity"
                        >
                        <?php if (
                            !empty($errors['legal_name'])
                        ): ?>
                            <small class="field-error">
                                <?= e(
                                    $errors['legal_name']
                                ) ?>
                            </small>
                        <?php endif; ?>
                    </div>

                    <div class="form-field">
                        <label for="contact_email">
                            Contact email
                            <span>Optional</span>
                        </label>
                        <input
                            id="contact_email"
                            name="contact_email"
                            type="email"
                            value="<?= e(
                                $old['contact_email']
                                ?? ''
                            ) ?>"
                            maxlength="190"
                            placeholder="admin@company.com"
                        >
                        <?php if (
                            !empty(
                                $errors['contact_email']
                            )
                        ): ?>
                            <small class="field-error">
                                <?= e(
                                    $errors[
                                        'contact_email'
                                    ]
                                ) ?>
                            </small>
                        <?php endif; ?>
                    </div>

                    <div class="form-field">
                        <label for="contact_phone">
                            Contact phone
                            <span>Optional</span>
                        </label>
                        <input
                            id="contact_phone"
                            name="contact_phone"
                            type="tel"
                            value="<?= e(
                                $old['contact_phone']
                                ?? ''
                            ) ?>"
                            maxlength="40"
                            placeholder="+254 700 000 000"
                        >
                        <?php if (
                            !empty(
                                $errors['contact_phone']
                            )
                        ): ?>
                            <small class="field-error">
                                <?= e(
                                    $errors[
                                        'contact_phone'
                                    ]
                                ) ?>
                            </small>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="card company-form-section">
                <div class="company-form-heading">
                    <span>02</span>
                    <div>
                        <h2>Company owner</h2>
                        <p>
                            Create the first tenant owner.
                            Access remains blocked until vendor
                            approval.
                        </p>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-field">
                        <label for="owner_display_name">
                            Owner name
                        </label>
                        <input
                            id="owner_display_name"
                            name="owner_display_name"
                            type="text"
                            value="<?= e(
                                $old[
                                    'owner_display_name'
                                ] ?? ''
                            ) ?>"
                            maxlength="120"
                            placeholder="Primary company owner"
                            required
                        >
                        <?php if (!empty(
                            $errors[
                                'owner_display_name'
                            ]
                        )): ?>
                            <small class="field-error">
                                <?= e($errors[
                                    'owner_display_name'
                                ]) ?>
                            </small>
                        <?php endif; ?>
                    </div>

                    <div class="form-field">
                        <label for="owner_username">
                            Owner username
                        </label>
                        <input
                            id="owner_username"
                            name="owner_username"
                            type="text"
                            value="<?= e(
                                $old['owner_username']
                                ?? ''
                            ) ?>"
                            maxlength="50"
                            autocomplete="off"
                            placeholder="company.owner"
                            required
                        >
                        <?php if (!empty(
                            $errors['owner_username']
                        )): ?>
                            <small class="field-error">
                                <?= e($errors[
                                    'owner_username'
                                ]) ?>
                            </small>
                        <?php endif; ?>
                    </div>

                    <div class="form-field form-field-wide">
                        <label for="owner_email">
                            Owner email
                        </label>
                        <input
                            id="owner_email"
                            name="owner_email"
                            type="email"
                            value="<?= e(
                                $old['owner_email'] ?? ''
                            ) ?>"
                            maxlength="190"
                            autocomplete="off"
                            placeholder="owner@company.com"
                            required
                        >
                        <?php if (!empty(
                            $errors['owner_email']
                        )): ?>
                            <small class="field-error">
                                <?= e($errors[
                                    'owner_email'
                                ]) ?>
                            </small>
                        <?php endif; ?>
                        <small class="field-help">
                            A secure temporary password will be
                            shown once after provisioning.
                        </small>
                    </div>
                </div>
            </section>

            <section class="card company-form-section">
                <div class="company-form-heading">
                    <span>03</span>
                    <div>
                        <h2>Locale and branding</h2>
                        <p>
                            Establish accounting, time and
                            visual defaults for this tenant.
                        </p>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-field">
                        <label for="country_code">
                            Country code
                        </label>
                        <input
                            id="country_code"
                            name="country_code"
                            type="text"
                            value="<?= e(
                                $old['country_code']
                                ?? 'KE'
                            ) ?>"
                            minlength="2"
                            maxlength="2"
                            placeholder="KE"
                            required
                        >
                        <?php if (
                            !empty(
                                $errors['country_code']
                            )
                        ): ?>
                            <small class="field-error">
                                <?= e(
                                    $errors['country_code']
                                ) ?>
                            </small>
                        <?php endif; ?>
                    </div>

                    <div class="form-field">
                        <label for="default_currency">
                            Default currency
                        </label>
                        <select
                            id="default_currency"
                            name="default_currency"
                            required
                        >
                            <?php foreach (
                                $currencies
                                as $code => $label
                            ): ?>
                                <option
                                    value="<?= e($code) ?>"
                                    <?= $selectedCurrency
                                        === $code
                                            ? 'selected'
                                            : '' ?>
                                >
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (
                            !empty(
                                $errors[
                                    'default_currency'
                                ]
                            )
                        ): ?>
                            <small class="field-error">
                                <?= e(
                                    $errors[
                                        'default_currency'
                                    ]
                                ) ?>
                            </small>
                        <?php endif; ?>
                    </div>

                    <div class="form-field form-field-wide">
                        <label for="timezone">
                            Company timezone
                        </label>
                        <select
                            id="timezone"
                            name="timezone"
                            required
                        >
                            <?php foreach (
                                $timezones
                                as $value => $label
                            ): ?>
                                <option
                                    value="<?= e($value) ?>"
                                    <?= $selectedTimezone
                                        === $value
                                            ? 'selected'
                                            : '' ?>
                                >
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (
                            !empty($errors['timezone'])
                        ): ?>
                            <small class="field-error">
                                <?= e(
                                    $errors['timezone']
                                ) ?>
                            </small>
                        <?php endif; ?>
                    </div>

                    <div class="form-field form-field-wide">
                        <label for="brand_primary_color">
                            Primary brand color
                        </label>
                        <div class="company-color-field">
                            <input
                                id="brand_primary_color"
                                name="brand_primary_color"
                                type="color"
                                value="<?= e($brandColor) ?>"
                                required
                            >
                            <span>
                                Used by tenant branding and
                                future customer themes.
                            </span>
                        </div>
                        <?php if (
                            !empty(
                                $errors[
                                    'brand_primary_color'
                                ]
                            )
                        ): ?>
                            <small class="field-error">
                                <?= e(
                                    $errors[
                                        'brand_primary_color'
                                    ]
                                ) ?>
                            </small>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="card company-form-section">
                <div class="company-form-heading">
                    <span>04</span>
                    <div>
                        <h2>Module subscription</h2>
                        <p>
                            Select only modules purchased by
                            this customer.
                        </p>
                    </div>
                </div>

                <?php if (
                    !empty($errors['modules'])
                ): ?>
                    <div class="alert alert-danger">
                        <?= e($errors['modules']) ?>
                    </div>
                <?php endif; ?>

                <div class="company-module-picker">
                    <?php foreach ($modules as $module): ?>
                        <?php
                        $canLicense = !empty(
                            $module['canLicense']
                        );
                        $code = (string) (
                            $module['code'] ?? ''
                        );
                        ?>
                        <label class="company-module-option">
                            <input
                                type="checkbox"
                                name="module_codes[]"
                                value="<?= e($code) ?>"
                                <?= in_array(
                                    $code,
                                    $selectedModules,
                                    true
                                )
                                    ? 'checked'
                                    : '' ?>
                                <?= !$canLicense
                                    ? 'disabled'
                                    : '' ?>
                            >
                            <span
                                class="module-product-icon"
                                aria-hidden="true"
                            >
                                <?= e(
                                    $module['icon_text']
                                    ?? 'MD'
                                ) ?>
                            </span>
                            <span>
                                <strong>
                                    <?= e(
                                        $module['name']
                                        ?? ''
                                    ) ?>
                                </strong>
                                <small>
                                    <?= e(
                                        $canLicense
                                            ? $module[
                                                'description'
                                            ] ?? ''
                                            : 'Roadmap — not available for licensing'
                                    ) ?>
                                </small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <aside class="company-provisioning-sidebar">
            <section class="card company-subscription-card">
                <span class="module-eyebrow">
                    Commercial terms
                </span>
                <h2>Subscription</h2>

                <div class="form-field">
                    <label for="subscription_status">
                        Initial status
                    </label>
                    <select
                        id="subscription_status"
                        name="subscription_status"
                        required
                    >
                        <option
                            value="active"
                            <?= $subscriptionStatus
                                === 'active'
                                    ? 'selected'
                                    : '' ?>
                        >
                            Active subscription
                        </option>
                        <option
                            value="trial"
                            <?= $subscriptionStatus
                                === 'trial'
                                    ? 'selected'
                                    : '' ?>
                        >
                            Trial subscription
                        </option>
                    </select>
                    <?php if (
                        !empty(
                            $errors[
                                'subscription_status'
                            ]
                        )
                    ): ?>
                        <small class="field-error">
                            <?= e(
                                $errors[
                                    'subscription_status'
                                ]
                            ) ?>
                        </small>
                    <?php endif; ?>
                </div>

                <div class="form-field">
                    <label for="subscription_expires_at">
                        Expiry date
                        <span>Required for trials</span>
                    </label>
                    <input
                        id="subscription_expires_at"
                        name="subscription_expires_at"
                        type="date"
                        value="<?= e(
                            $old[
                                'subscription_expires_at'
                            ] ?? ''
                        ) ?>"
                    >
                    <?php if (
                        !empty(
                            $errors[
                                'subscription_expires_at'
                            ]
                        )
                    ): ?>
                        <small class="field-error">
                            <?= e(
                                $errors[
                                    'subscription_expires_at'
                                ]
                            ) ?>
                        </small>
                    <?php endif; ?>
                </div>

                <div class="company-provisioning-note">
                    <strong>Atomic provisioning</strong>
                    <p>
                        The pending workspace, owner account,
                        module licenses and audit entry are
                        created together.
                    </p>
                </div>
            </section>

            <div class="company-form-actions">
                <a
                    href="/office_app/public/administration/companies"
                    class="btn btn-secondary"
                >
                    Cancel
                </a>
                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Provision company
                </button>
            </div>
        </aside>
    </div>
</form>
