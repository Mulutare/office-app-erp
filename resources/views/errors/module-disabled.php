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
        Module Unavailable |
        <?= e(
            $data['applicationName']
            ?? 'OfficeApp ERP'
        ) ?>
    </title>
    <link
        rel="stylesheet"
        href="/office_app/public/assets/css/app.css"
    >
</head>
<body>
    <main class="error-page">
        <section class="error-card">
            <p class="error-code">404</p>
            <h1>Module unavailable</h1>
            <p>
                This ERP module is not enabled for
                your company.
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
