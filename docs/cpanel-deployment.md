# cPanel deployment runbook

This runbook targets the verified hosting profile supplied for
`passiontechnologiesplc.com`: cPanel, Apache, PHP-FPM and PHP 8.1.
OfficeApp also remains supported in its PHP 8.4 Docker runtime.

## Recommended production layout

Create a dedicated HTTPS subdomain such as:

```text
erp.passiontechnologiesplc.com
```

Extract OfficeApp outside the main website document root:

```text
/home/CPANEL_USER/office_app
```

Set the subdomain document root to:

```text
/home/CPANEL_USER/office_app/public
```

Only `public/` may be web-accessible. Do not point a domain at the
repository root.

## Hosting requirements

- PHP 8.1 or newer with PHP-FPM enabled.
- PDO, `pdo_mysql`, `curl`, `mbstring`, OpenSSL, session and OPcache.
- MySQL 8 or MariaDB 10.6 or newer with InnoDB and `utf8mb4`.
- HTTPS certificate for the ERP hostname.
- cPanel Terminal or SSH is strongly recommended for reviewed migrations.
- A cPanel cron job is required for durable attendance notifications.

Use MultiPHP Manager to select PHP 8.1 or a newer supported version for
the ERP hostname. Use Select PHP Version or PHP Extensions to verify the
extensions above.

The release package includes the locked Composer dependencies used for
standards-based Web Push. Build it only after:

```text
composer install --no-dev --optimize-autoloader
```

The packaging tool refuses to create an incomplete release when
`vendor/autoload.php` is missing.

## Production configuration

Copy these examples without committing the resulting secret files:

```text
config/database.example.php -> config/database.php
config/app.local.example.php -> config/app.local.php
deployment/cpanel/.user.ini.example -> public/.user.ini
```

Use `base_path => ''` when the subdomain document root is `public/`.
Set the correct production timezone and keep:

```php
'environment' => 'production',
'debug' => false,
'session_cookie_secure' => true,
```

### Background attendance notifications

Generate one stable VAPID identity on the trusted workstation after Composer
dependencies are installed:

```text
php bin/generate-web-push-keys.php
```

Copy the generated values into the ignored `config/app.local.php` under
`web_push`, set the real administrator contact in `subject`, and change
`enabled` to `true`. Never commit or paste the private key into screenshots.
Do not rotate the VAPID keys during a routine release: browsers must register
again after a key rotation.

The default endpoint allowlist accepts the standard Google, Mozilla, Apple and
Microsoft browser push services. If a supported browser uses another service,
review and add its exact hostname rather than allowing arbitrary destinations.
OfficeApp rejects IP endpoints and unapproved hosts to prevent the subscription
API from becoming a server-side request-forgery path.

Create a dedicated database and database user in cPanel MySQL Databases.
Grant that user all privileges only on the OfficeApp database. Put those
generated names and a unique password in `config/database.php`.

## Release and database sequence

Build a secret-free release package on the trusted workstation:

```powershell
powershell -ExecutionPolicy Bypass -File tools/build-cpanel-package.ps1
```

Upload and extract `dist/officeapp-cpanel.zip`. Before directing real
users to the application:

1. Back up the database and the previous release.
2. Verify `storage/cache`, `storage/logs` and `storage/uploads` are
   writable by the PHP-FPM account.
3. Run `php bin/check-runtime.php`.
4. Use the fresh-install or upgrade sequence below.
5. Create the first vendor administrator through the reviewed CLI below,
   never through a public installer.
6. Open `/login`, sign in, verify the vendor workspace, then verify one
   approved tenant in a separate browser session.

Never import only selected new tables. The migration ledger and
repository schema must move together.

### Fresh empty database

Use this once when the cPanel database contains no tables:

```text
export OFFICEAPP_INSTALL_CONFIRM=INSTALL_EMPTY_DATABASE
php bin/install-database.php
unset OFFICEAPP_INSTALL_CONFIRM
```

The installer refuses non-empty databases. It applies the same reviewed
MySQL schema files used by Docker, records the versioned migration baseline
and synchronizes reference data. If it reports a DDL failure, recreate the
empty database before retrying.

### Existing database upgrade

After taking a verified backup:

```text
php bin/migrate.php
php bin/sync-reference-data.php
```

Do not run the fresh installer during an upgrade.

### First software-owner administrator

Run this only on a fresh database after migration and reference-data
sync. The command refuses to run when a platform administrator already
exists.

```text
read -s -p "Initial administrator password: " OFFICEAPP_INITIAL_ADMIN_PASSWORD
echo
export OFFICEAPP_INITIAL_ADMIN_PASSWORD
php bin/create-platform-administrator.php \
  --username=platform.admin \
  --email=admin@example.com \
  --name="Platform Administrator"
unset OFFICEAPP_INITIAL_ADMIN_PASSWORD
```

Use the real software-owner email. The password is not printed or stored
in deployment files, and the administrator must replace it at first
sign-in. Existing administrators must be managed through OfficeApp's
protected administration screens; this bootstrap is not a reset tool.

## Attendance notification cron

Use these production commands from cPanel Cron Jobs. Each command uses a non-overlap lock and records its run in the ERP operations table:

```text
* * * * * /usr/bin/flock -n /tmp/officeapp-integration.lock /usr/local/bin/php /home/passiontech/office_app/bin/run-production-task.php integration
* * * * * /usr/bin/flock -n /tmp/officeapp-webhooks.lock /usr/local/bin/php /home/passiontech/office_app/bin/run-production-task.php webhooks
* * * * * /usr/bin/flock -n /tmp/officeapp-attendance.lock /usr/local/bin/php /home/passiontech/office_app/bin/run-production-task.php attendance
```

If the host exposes PHP 8.1+ at a different cPanel path, select that binary in cPanel. The runner also takes a database advisory lock, so overlapping invocations fail safely even if `flock` is unavailable.

In cPanel Cron Jobs, run every minute with the actual account path:

```text
* * * * * /usr/local/bin/ea-php81 /home/CPANEL_USER/office_app/bin/queue-attendance-notifications.php >/dev/null 2>&1
```

Confirm the PHP path in cPanel Terminal with `command -v php`. Some hosts
use `/usr/local/bin/php` instead.

This single command creates the private in-application reminders and sends
pending background Web Push deliveries. Delivery retries use a bounded
backoff, and expired browser subscriptions are disabled automatically.

## Rollback

Application rollback means restoring the previous release and its matching
database backup. Do not edit `schema_migrations` or manually drop the new
attendance columns. MySQL DDL may auto-commit, so a verified backup is the
only safe production rollback.

## Required access for assisted deployment

An assisted deployment cannot begin until the operator supplies, through a
secure channel:

- the desired ERP hostname;
- cPanel File Manager or SFTP/SSH access;
- cPanel database name, user and host;
- confirmation that a fresh backup exists;
- the approved maintenance window.

Passwords and private keys must never be pasted into source files, Git
commits, screenshots or chat transcripts.
