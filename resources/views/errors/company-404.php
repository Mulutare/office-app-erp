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
        Company Not Found |
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
            <h1>Company not found</h1>
            <p>
                The requested customer company does not
                exist or is no longer available.
            </p>
            <a
                href="/office_app/public/administration/companies"
                class="btn btn-primary"
            >
                Return to companies
            </a>
        </section>
    </main>
</body>
</html>
