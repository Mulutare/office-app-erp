<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>
        Dashboard |
        <?= e($data['applicationName'] ?? 'OfficeApp ERP') ?>
    </title>
</head>

<body>
    <h1>
        <?= e($data['applicationName'] ?? 'OfficeApp ERP') ?>
    </h1>

    <h2>Dashboard</h2>

    <p>
        Welcome,
        <strong>
            <?= e(
                $data['user']['display_name']
                ?? 'User'
            ) ?>
        </strong>
    </p>

    <p>
        Username:
        <strong>
            <?= e(
                $data['user']['username']
                ?? ''
            ) ?>
        </strong>
    </p>

    <p>
        Roles:
        <strong>
            <?= e(
                implode(
                    ', ',
                    $data['user']['roles']
                    ?? []
                )
            ) ?>
        </strong>
    </p>

    <?php if (
        !empty(
            $data['user']['must_change_password']
        )
    ): ?>
        <p role="alert">
            <strong>
                You must change your temporary password.
            </strong>
        </p>
    <?php endif; ?>

    <form
        method="post"
        action="/office_app/public/logout"
    >
        <?= csrfField() ?>

        <button type="submit">
            Sign Out
        </button>
    </form>
</body>
</html>