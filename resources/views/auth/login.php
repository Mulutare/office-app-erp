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
$companyName = (string) (
    $company['name'] ?? 'Your Company'
);
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
        Sign In | <?= e($applicationName) ?>
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
                            Modular Enterprise Platform
                        </span>
                    </div>
                </div>

                <div class="auth-showcase-copy">
                    <span class="auth-kicker">
                        One secure workspace
                    </span>
                    <h1>
                        Run your business with the modules
                        that matter.
                    </h1>
                    <p>
                        A configurable ERP workspace for
                        people, finance, operations and
                        customer service.
                    </p>
                </div>

                <div class="auth-value-list">
                    <div class="auth-value-item">
                        <span aria-hidden="true">01</span>
                        <div>
                            <strong>Company configured</strong>
                            <small>
                                Only licensed modules are available.
                            </small>
                        </div>
                    </div>
                    <div class="auth-value-item">
                        <span aria-hidden="true">02</span>
                        <div>
                            <strong>Role protected</strong>
                            <small>
                                Every operation respects user permissions.
                            </small>
                        </div>
                    </div>
                    <div class="auth-value-item">
                        <span aria-hidden="true">03</span>
                        <div>
                            <strong>Activity audited</strong>
                            <small>
                                Security and business events are traceable.
                            </small>
                        </div>
                    </div>
                </div>
            </div>

            <p class="auth-showcase-footer">
                Secure access for authorized personnel only.
            </p>
        </section>

        <section class="auth-form-panel">
            <div class="auth-card">
                <div class="auth-company-context">
                    <span aria-hidden="true">CO</span>
                    <div>
                        <small>Company workspace</small>
                        <strong>
                            <?= e($companyName) ?>
                        </strong>
                    </div>
                </div>

                <header class="auth-card-header">
                    <span class="auth-kicker auth-kicker-dark">
                        Welcome back
                    </span>
                    <h2>Sign in to your account</h2>
                    <p>
                        Enter your assigned username or work
                        email and password.
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
                    action="/office_app/public/login"
                    class="auth-form"
                >
                    <?= csrfField() ?>

                    <div class="auth-field">
                        <label for="login">
                            Username or email
                        </label>
                        <input
                            id="login"
                            name="login"
                            type="text"
                            value="<?= e(old('login')) ?>"
                            autocomplete="username"
                            maxlength="190"
                            placeholder="Enter your username or work email"
                            required
                            autofocus
                        >
                    </div>

                    <div class="auth-field">
                        <label for="password">
                            Password
                        </label>
                        <div class="auth-password-field">
                            <input
                                id="password"
                                name="password"
                                type="password"
                                autocomplete="current-password"
                                maxlength="255"
                                placeholder="Enter your password"
                                required
                            >
                            <button
                                type="button"
                                class="auth-password-toggle"
                                data-password-toggle="password"
                                aria-label="Show password"
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
                        Sign in securely
                    </button>
                </form>

                <div class="auth-support-note">
                    <strong>Cannot sign in?</strong>
                    <span>
                        Contact your company system
                        administrator for account assistance.
                    </span>
                </div>

                <footer class="auth-card-footer">
                    <span>
                        Protected by role-based access control
                    </span>
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
