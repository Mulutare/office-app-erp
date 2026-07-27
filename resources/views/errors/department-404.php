<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$applicationName = (string) (
    $data['applicationName'] ?? 'OfficeApp ERP'
);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >
    <title>
        Department not found |
        <?= e($applicationName) ?>
    </title>
    <link
        rel="stylesheet"
        href="<?= e(assetUrl('css/app.css')) ?>"
    >
</head>
<body class="error-page">
    <main class="error-card">
        <span class="error-code">404</span>
        <h1>Department not found</h1>
        <p>
            The department may have been removed or the
            address is incorrect.
        </p>
        <a
            href="/office_app/public/hr/departments"
            class="btn btn-primary"
        >
            Back to departments
        </a>
    </main>
</body>
</html>
