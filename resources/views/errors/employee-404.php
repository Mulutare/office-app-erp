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
        Employee Not Found |
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
            <p class="error-code">404</p>
            <h1>Employee not found</h1>
            <p>
                The requested employee record does not exist.
            </p>
            <a
                href="/office_app/public/hr"
                class="btn btn-primary"
            >
                Return to employees
            </a>
        </section>
    </main>
</body>
</html>
