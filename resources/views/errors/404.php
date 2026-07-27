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
        User Not Found |
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

            <h1>User not found</h1>

            <p>
                The requested account does not exist
                or is no longer available.
            </p>

            <a
                href="/office_app/public/administration/users"
                class="btn btn-primary"
            >
                Return to users
            </a>
        </section>
    </main>
</body>
</html>
