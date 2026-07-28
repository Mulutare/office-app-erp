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
    <meta name="robots" content="noindex, nofollow">
    <title>
        Job title not found | <?= e($applicationName) ?>
    </title>
    <link
        rel="stylesheet"
        href="<?= e(assetUrl('css/app.css')) ?>"
    >
</head>
<body class="error-page">
    <main class="error-card">
        <span class="error-code">404</span>
        <h1>Job title not found</h1>
        <p>
            The job title does not exist in the current
            company workspace or is no longer available.
        </p>
        <a
            href="/office_app/public/organization/job-titles"
            class="btn btn-primary"
        >
            Back to job titles
        </a>
    </main>
</body>
</html>
