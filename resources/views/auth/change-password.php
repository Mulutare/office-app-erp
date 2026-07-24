<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>
        Change Password |
        <?= e($data['applicationName'] ?? 'OfficeApp ERP') ?>
    </title>
</head>

<body>
    <h1>
        <?= e($data['applicationName'] ?? 'OfficeApp ERP') ?>
    </h1>

    <h2>Change temporary password</h2>

    <p>
        You must replace your temporary password before continuing.
    </p>

    <?php if (!empty($data['error'])): ?>
        <p role="alert">
            <strong><?= e($data['error']) ?></strong>
        </p>
    <?php endif; ?>

    <form
        method="post"
        action="/office_app/public/change-password"
    >
        <?= csrfField() ?>

        <div>
            <label for="current_password">
                Current password
            </label>

            <input
                id="current_password"
                name="current_password"
                type="password"
                autocomplete="current-password"
                required
                autofocus
            >
        </div>

        <br>

        <div>
            <label for="new_password">
                New password
            </label>

            <input
                id="new_password"
                name="new_password"
                type="password"
                autocomplete="new-password"
                minlength="12"
                required
            >
        </div>

        <br>

        <div>
            <label for="new_password_confirmation">
                Confirm new password
            </label>

            <input
                id="new_password_confirmation"
                name="new_password_confirmation"
                type="password"
                autocomplete="new-password"
                minlength="12"
                required
            >
        </div>

        <br>

        <button type="submit">
            Change Password
        </button>
    </form>
</body>
</html>