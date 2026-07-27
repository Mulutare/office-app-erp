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
        Audit Event Not Found |
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
            <h1>Audit event not found</h1>
            <p>
                The requested audit event does not exist.
            </p>
            <a
                href="/office_app/public/administration/audit-logs"
                class="btn btn-primary"
            >
                Return to audit logs
            </a>
        </section>
    </main>
</body>
</html>
