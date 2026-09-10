<?php
$notificationCompany = (new \App\Services\TenantContext())->companyIdOrNull();
$notificationUser = (int) ($_SESSION['auth']['user_id'] ?? 0);
if ($notificationCompany !== null && $notificationUser > 0):
    $notificationService = new \App\Services\UserNotificationService();
    $notificationCount = $notificationService->unreadCount($notificationCompany,$notificationUser);
    $notifications = $notificationService->recentForUser($notificationCompany,$notificationUser);
?>
<details class="user-notifications">
    <summary class="btn btn-secondary" aria-label="Notifications">
        <span aria-hidden="true">&#128276;</span>
        <?php if ($notificationCount > 0): ?><strong class="notification-badge"><?= e($notificationCount) ?></strong><?php endif; ?>
    </summary>
    <section class="card notification-panel" aria-label="Recent notifications">
        <h2>Notifications</h2>
        <form method="post" action="<?= e(appBasePath()) ?>/notifications/read-all">
            <?= csrfField() ?><button class="btn btn-secondary btn-compact">Mark all as read</button>
        </form>
        <?php if ($notifications === []): ?><p>No notifications</p><?php endif; ?>
        <?php foreach ($notifications as $notification): ?>
        <form method="post" action="<?= e(appBasePath()) ?>/notifications/<?= e($notification['notification_id']) ?>/read">
            <?= csrfField() ?>
            <button class="notification-item <?= $notification['read_at'] === null ? 'notification-unread' : '' ?>">
                <strong><?= e($notification['title']) ?></strong>
                <span><?= e(mb_strimwidth($notification['message'],0,180,'…')) ?></span>
                <time><?= e($notification['created_at']) ?></time>
            </button>
        </form>
        <?php endforeach; ?>
    </section>
</details>
<style>
.user-notifications{position:relative}.user-notifications summary{cursor:pointer;list-style:none}
.notification-panel{position:absolute;right:0;top:100%;width:min(360px,85vw);max-height:70vh;overflow:auto;z-index:1000}
.notification-item{display:flex;flex-direction:column;gap:.3rem;width:100%;text-align:left;padding:.8rem;border:0;border-bottom:1px solid #d5dce5;background:transparent;color:inherit;cursor:pointer}
.notification-unread{background:#edf4ff;border-left:3px solid #2563eb}.notification-item time{font-size:.8rem}
.notification-badge{border-radius:1rem;padding:.1rem .4rem;background:#b91c1c;color:white}
</style>
<?php endif; ?>
