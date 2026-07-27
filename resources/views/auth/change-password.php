<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$applicationName = (string) (
    $data['applicationName'] ?? 'OfficeApp ERP'
);
$company = is_array(
    $data['company'] ?? null
)
    ? $data['company']
    : [];
$user = is_array($data['user'] ?? null)
    ? $data['user']
    : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >
    <meta
        name="robots"
        content="noindex, nofollow"
    >
    <title>
        Secure Your Account |
        <?= e($applicationName) ?>
    </title>
    <link
        rel="stylesheet"
        href="<?= e(assetUrl('css/app.css')) ?>"
    >
</head>

<body class="auth-body">
    <main class="auth-shell">
        <section class="auth-showcase">
            <div class="auth-showcase-inner">
                <div class="auth-product-brand">
                    <span class="auth-logo-frame">
                        <img
                            src="<?= e(assetUrl(
                                'images/company-logo.png'
                            )) ?>"
                            alt=""
                            onerror="this.style.display='none'"
                        >
                    </span>
                    <div>
                        <strong>
                            <?= e($applicationName) ?>
                        </strong>
                        <span>
                            <?= e(
                                $company['name']
                                ?? 'Company Workspace'
                            ) ?>
                        </span>
                    </div>
                </div>

                <div class="auth-showcase-copy">
                    <span class="auth-kicker">
                        First sign-in protection
                    </span>
                    <h1>
                        Create a password only you know.
                    </h1>
                    <p>
                        Temporary passwords must be replaced
                        before access to company modules is
                        granted.
                    </p>
                </div>

                <div class="password-requirements">
                    <strong>Password requirements</strong>
                    <ul>
                        <li>At least 12 characters</li>
                        <li>Uppercase and lowercase letters</li>
                        <li>A number and a special character</li>
                        <li>Different from your temporary password</li>
                    </ul>
                </div>
            </div>

            <p class="auth-showcase-footer">
                Your password is stored as a secure hash.
            </p>
        </section>

        <section class="auth-form-panel">
            <div class="auth-card auth-card-password">
                <div class="auth-company-context">
                    <span aria-hidden="true">ID</span>
                    <div>
                        <small>Securing account</small>
                        <strong>
                            <?= e(
                                $user['display_name']
                                ?? $user['username']
                                ?? 'ERP User'
                            ) ?>
                        </strong>
                    </div>
                </div>

                <header class="auth-card-header">
                    <span class="auth-kicker auth-kicker-dark">
                        Required action
                    </span>
                    <h2>Change temporary password</h2>
                    <p>
                        Confirm your temporary password, then
                        choose a strong permanent password.
                    </p>
                </header>

                <?php if (!empty($data['error'])): ?>
                    <div
                        class="auth-alert"
                        role="alert"
                    >
                        <span aria-hidden="true">!</span>
                        <p>
                            <?= e($data['error']) ?>
                        </p>
                    </div>
                <?php endif; ?>

                <form
                    method="post"
                    action="/office_app/public/change-password"
                    class="auth-form"
                >
                    <?= csrfField() ?>

                    <div class="auth-field">
                        <label for="current_password">
                            Temporary password
                        </label>
                        <div class="auth-password-field">
                            <input
                                id="current_password"
                                name="current_password"
                                type="password"
                                autocomplete="current-password"
                                placeholder="Enter temporary password"
                                required
                                autofocus
                            >
                            <button
                                type="button"
                                class="auth-password-toggle"
                                data-password-toggle="current_password"
                                aria-label="Show temporary password"
                                aria-pressed="false"
                            >
                                Show
                            </button>
                        </div>
                    </div>

                    <div class="auth-field">
                        <label for="new_password">
                            New password
                        </label>
                        <div class="auth-password-field">
                            <input
                                id="new_password"
                                name="new_password"
                                type="password"
                                autocomplete="new-password"
                                minlength="12"
                                placeholder="Create a strong password"
                                required
                            >
                            <button
                                type="button"
                                class="auth-password-toggle"
                                data-password-toggle="new_password"
                                aria-label="Show new password"
                                aria-pressed="false"
                            >
                                Show
                            </button>
                        </div>
                    </div>

                    <div class="auth-field">
                        <label for="new_password_confirmation">
                            Confirm new password
                        </label>
                        <div class="auth-password-field">
                            <input
                                id="new_password_confirmation"
                                name="new_password_confirmation"
                                type="password"
                                autocomplete="new-password"
                                minlength="12"
                                placeholder="Repeat the new password"
                                required
                            >
                            <button
                                type="button"
                                class="auth-password-toggle"
                                data-password-toggle="new_password_confirmation"
                                aria-label="Show password confirmation"
                                aria-pressed="false"
                            >
                                Show
                            </button>
                        </div>
                    </div>

                    <button
                        type="submit"
                        class="btn btn-primary auth-submit"
                    >
                        Save password and continue
                    </button>
                </form>

                <footer class="auth-card-footer">
                    <span>Secure account activation</span>
                    <span aria-hidden="true">&bull;</span>
                    <span><?= e(date('Y')) ?></span>
                </footer>
            </div>
        </section>
    </main>

    <script
        src="<?= e(assetUrl('js/app.js')) ?>"
        defer
    ></script>
</body>
</html>
