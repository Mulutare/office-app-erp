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
        Access Denied |
        <?= e(
            $data['applicationName']
            ?? 'OfficeApp ERP'
        ) ?>
    </title>

    <link
        rel="stylesheet"
        href="<?= e(assetUrl('css/app.css')) ?>"
    >
</head>

<body>
    <main class="error-page">
        <section class="error-card">
            <p class="error-code">403</p>

            <h1>Access denied</h1>

            <p>
                Your account does not have permission
                to access this resource.
            </p>

            <div class="page-actions">
                <a
                    href="<?= e(appBasePath()) ?>/dashboard"
                    class="btn btn-primary"
                >
                    Return to dashboard
                </a>

                <form
                    method="post"
                    action="<?= e(appBasePath()) ?>/logout"
                >
                    <?= csrfField() ?>

                    <button
                        type="submit"
                        class="btn btn-secondary"
                    >
                        Sign out and return to login
                    </button>
                </form>
            </div>
        </section>
    </main>
</body>
</html>
