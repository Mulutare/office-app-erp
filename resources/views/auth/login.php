<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>
        Login |
        <?= e($data['applicationName'] ?? 'OfficeApp ERP') ?>
    </title>
</head>

<body>
    <h1>
        <?= e($data['applicationName'] ?? 'OfficeApp ERP') ?>
    </h1>

    <h2>Sign in</h2>

    <?php if (!empty($data['error'])): ?>
        <p role="alert">
            <strong><?= e($data['error']) ?></strong>
        </p>
    <?php endif; ?>

    <form
        method="post"
        action="/office_app/public/login"
        autocomplete="off"
    >
        <?= csrfField() ?>

        <div>
            <label for="login">
                Username or email
            </label>

            <input
                id="login"
                name="login"
                type="text"
                value="<?= e(old('login')) ?>"
                autocomplete="username"
                maxlength="190"
                required
                autofocus
            >
        </div>

        <br>

        <div>
            <label for="password">
                Password
            </label>

            <input
                id="password"
                name="password"
                type="password"
                autocomplete="current-password"
                maxlength="255"
                required
            >
        </div>

        <br>

        <button type="submit">
            Sign In
        </button>
    </form>
</body>
</html>