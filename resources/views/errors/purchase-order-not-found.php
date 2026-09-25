<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Purchase Order Not Found | <?= e($data['applicationName'] ?? 'OfficeApp ERP') ?></title>
    <link rel="stylesheet" href="<?= e(assetUrl('css/app.css')) ?>">
</head>
<body>
    <main class="error-page">
        <section class="error-card">
            <p class="error-code">404</p>
            <h1>Purchase order not found</h1>
            <p>The requested purchase order does not exist or is not available to your account.</p>
            <a href="<?= e(appBasePath()) ?>/procurement" class="btn btn-primary">Return to Procurement</a>
        </section>
    </main>
</body>
</html>
