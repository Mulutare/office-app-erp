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
        <p class="notification-explainer">Unread notices are event history. Action Required tracks current work even after a notice is read.</p>
        <form method="post" action="<?= e(appBasePath()) ?>/notifications/read-all">
            <?= csrfField() ?><button class="btn btn-secondary btn-compact">Mark all as read</button>
        </form>
        <?php if ($notifications === []): ?><p>No notifications</p><?php endif; ?>
        <?php foreach ($notifications as $notification): ?>
        <?php $target = \App\Services\WorkspaceAccessService::forPath((string)$notification['action_url']);
        if ($target !== null && !\App\Services\WorkspaceAccessService::allowed($target)): ?>
        <div class="notification-item"><strong><?= e($notification['title']) ?></strong><span><?= e(mb_strimwidth($notification['message'],0,180,'…')) ?></span><time><?= e($notification['created_at']) ?></time></div>
        <?php continue; endif; ?>
        <form method="post" action="<?= e(appBasePath()) ?>/notifications/<?= e($notification['notification_id']) ?>/read">
            <?= csrfField() ?>
            <button class="notification-item <?= $notification['read_at'] === null ? 'notification-unread' : '' ?>" aria-label="<?= e($notification['title'].' — '.$notification['message']) ?>">
                <strong><?= e($notification['title']) ?></strong>
                <span><?= e(mb_strimwidth($notification['message'],0,180,'…')) ?></span>
                <time><?= e($notification['created_at']) ?></time>
            </button>
        </form>
        <?php endforeach; ?>
    </section>
</details>
<?php endif; ?>
