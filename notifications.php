<?php
require __DIR__ . '/includes/bootstrap.php';
$me = require_login();

if (is_post()) {
    verify_csrf();
    if (input('action') === 'clear') {
        q('DELETE FROM notifications WHERE user_id = ? AND is_read = 1', [$me['id']]);
        flash('success', 'Read notifications cleared.');
    }
    redirect('notifications.php');
}

$items = q(
    'SELECT n.*, p.category FROM notifications n LEFT JOIN posts p ON p.id = n.post_id
     WHERE n.user_id = ? ORDER BY n.created_at DESC LIMIT 100',
    [$me['id']]
)->fetchAll();

// Opening the page marks everything as read (rendered list still shows what was new).
q('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0', [$me['id']]);

$pageTitle = 'Notifications';
require __DIR__ . '/includes/header.php';
?>
<section class="page-head row">
    <div>
        <h1>Notifications</h1>
        <p class="muted">Alerts from your <a href="areas.php">watch areas</a>, friend requests, replies and moderation updates.</p>
    </div>
    <?php if ($items): ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="clear">
            <button class="btn btn-sm" type="submit">Clear read</button>
        </form>
    <?php endif; ?>
</section>

<div class="card list">
    <?php if (!$items): ?>
        <p class="muted">Nothing yet. <a href="areas.php">Add a watch area</a> to get alerts about places you care about.</p>
    <?php endif; ?>
    <?php foreach ($items as $n): ?>
        <?php $isFriend = in_array($n['type'], ['friend_request', 'friend_accept'], true); ?>
        <a class="notif<?= $n['is_read'] ? '' : ' unread' ?>" href="<?= $n['post_id'] ? 'post.php?id=' . (int)$n['post_id'] : ($n['type'] === 'friend_request' ? 'people.php?tab=requests' : ($isFriend ? 'people.php?tab=friends' : '#')) ?>">
            <span class="notif-ic"><?= $isFriend ? '👥' : (str_starts_with((string)$n['type'], 'pet_') ? '🐾' : ($n['category'] && category($n['category']) ? category($n['category'])['icon'] : '🔔')) ?></span>
            <span class="notif-text">
                <span><?= e($n['message']) ?></span>
                <span class="notif-time"><?= e(time_ago($n['created_at'])) ?></span>
            </span>
            <?php if (!$n['is_read']): ?><span class="notif-dot"></span><?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
