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
        Role Not Found |
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
            <h1>Role not found</h1>
            <p>
                The requested role does not exist.
            </p>
            <a
                href="/office_app/public/administration/roles"
                class="btn btn-primary"
            >
                Return to roles
            </a>
        </section>
    </main>
</body>
</html>
