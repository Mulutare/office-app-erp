<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];

$applicationName = (string) (
    $data['applicationName'] ?? 'PassionTech ERP'
);

$error = (string) ($data['error'] ?? '');
$oldLogin = (string) old('login');
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

    <title>Sign In | PassionTech ERP</title>

    <link
        rel="stylesheet"
        href="<?= e(assetUrl('css/login.css')) ?>"
    >
</head>

<body class="pt-login-body">
    <main class="pt-login-page">
        <section
            class="pt-login-card"
            aria-labelledby="pt-login-title"
        >
            <header class="pt-login-brand">
                <img
                    class="pt-login-logo"
                    src="<?= e(assetUrl(
                        'images/passiontech-logo.png'
                    )) ?>"
                    alt="Passion Technologies"
                >

                <h1 id="pt-login-title">
                    PassionTech ERP
                </h1>
            </header>

            <div class="pt-login-divider"></div>

            <?php if ($error !== ''): ?>
                <div
                    class="pt-login-error"
                    role="alert"
                >
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <section
                id="pt-account-picker"
                class="pt-account-picker"
                hidden
            >
                <p class="pt-picker-label">
                    Choose a user
                </p>

                <div
                    id="pt-account-list"
                    class="pt-account-list"
                ></div>

                <button
                    type="button"
                    id="pt-use-another"
                    class="pt-use-another"
                >
                    <span
                        class="pt-user-outline"
                        aria-hidden="true"
                    >
                        ◉
                    </span>
                    Use another user
                </button>
            </section>

            <form
                id="pt-login-form"
                method="post"
                action="/office_app/public/login"
                class="pt-login-form"
                data-has-error="<?= $error !== '' ? '1' : '0' ?>"
                data-old-login="<?= e($oldLogin) ?>"
            >
                <?= csrfField() ?>

                <button
                    type="button"
                    id="pt-back-to-users"
                    class="pt-back-button"
                    hidden
                >
                    ← Back
                </button>

                <p class="pt-form-heading">
                    Sign in
                </p>

                <div class="pt-field">
                    <label for="login">
                        Username or email
                    </label>

                    <input
                        id="login"
                        name="login"
                        type="text"
                        value="<?= e($oldLogin) ?>"
                        autocomplete="username"
                        maxlength="190"
                        required
                        autofocus
                    >
                </div>

                <div class="pt-field">
                    <label for="password">
                        Password
                    </label>

                    <div class="pt-password-wrap">
                        <input
                            id="password"
                            name="password"
                            type="password"
                            autocomplete="current-password"
                            maxlength="255"
                            required
                        >

                        <button
                            id="pt-password-toggle"
                            class="pt-password-toggle"
                            type="button"
                            aria-label="Show password"
                        >
                            Show
                        </button>
                    </div>
                </div>

                <button
                    type="submit"
                    class="pt-sign-in-button"
                >
                    Sign in
                </button>
            </form>

            <footer class="pt-login-footer">
                Authorized personnel only
            </footer>
        </section>
    </main>

    <script
        src="<?= e(assetUrl('js/login.js')) ?>"
        defer
    ></script>
</body>
</html>
