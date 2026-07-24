<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>
        Login | <?= e($data['applicationName'] ?? 'OfficeApp ERP') ?>
    </title>
</head>

<body>
    <h1><?= e($data['applicationName'] ?? 'OfficeApp ERP') ?></h1>

    <h2>Sign in</h2>

    <form method="post" action="/office_app/public/login">
        <div>
            <label for="username">Username or email</label>

            <input
                id="username"
                name="username"
                type="text"
                autocomplete="username"
                required
            >
        </div>

        <br>

        <div>
            <label for="password">Password</label>

            <input
                id="password"
                name="password"
                type="password"
                autocomplete="current-password"
                required
            >
        </div>

        <br>

        <button type="submit">Sign In</button>
    </form>
</body>
</html>