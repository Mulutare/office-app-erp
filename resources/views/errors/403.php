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

            <a
                href="/office_app/public/dashboard"
                class="btn btn-primary"
            >
                Return to dashboard
            </a>
        </section>
    </main>
</body>
</html>
