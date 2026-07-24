<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title><?= e($data['applicationName'] ?? 'OfficeApp ERP') ?></title>
</head>

<body>
    <h1><?= e($data['applicationName'] ?? 'OfficeApp ERP') ?></h1>

    <p>
        Architecture:
        <strong>Controller and View working</strong>
    </p>

    <p>
        Environment:
        <strong><?= e($data['environment'] ?? 'unknown') ?></strong>
    </p>

    <p>
        Database:
        <strong><?= e($data['databaseName'] ?? 'unknown') ?></strong>
    </p>

    <p>
        Server time:
        <strong><?= e($data['serverTime'] ?? '') ?></strong>
    </p>

    <p>
        <a href="/office_app/public/login">
            Open Login
        </a>
    </p>
</body>
</html>