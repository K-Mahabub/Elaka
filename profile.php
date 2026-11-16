<?php
require __DIR__ . '/includes/bootstrap.php';
$me = require_login();

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'name') {
        $name = input('name');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            flash('error', 'Name must be 2–80 characters.');
        } else {
            q('UPDATE users SET name = ? WHERE id = ?', [$name, $me['id']]);
            flash('success', 'Name updated.');
        }
    } elseif ($action === 'password') {
        $current = (string)($_POST['current'] ?? '');
        $new = (string)($_POST['new'] ?? '');
        if (!password_verify($current, $me['password_hash'])) {
            flash('error', 'Current password is incorrect.');
        } elseif (strlen($new) < 8) {
            flash('error', 'New password must be at least 8 characters.');
        } elseif ($new !== ($_POST['confirm'] ?? '')) {
            flash('error', 'New passwords do not match.');
        } else {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $me['id']]);
            session_regenerate_id(true);
            flash('success', 'Password changed.');
        }
    }
    redirect('profile.php');
}

$myPosts = q(
    "SELECT p.*, u.name AS author_name, u.role AS author_role,
            (SELECT COUNT(*) FROM reactions r WHERE r.post_id = p.id) AS reaction_count,
            (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id AND c.status = 'active') AS comment_count,
            NULL AS my_reaction
     FROM posts p JOIN users u ON u.id = p.user_id
     WHERE p.user_id = ? ORDER BY p.created_at DESC LIMIT 100",
    [$me['id']]
)->fetchAll();

$stats = q(
    "SELECT (SELECT COUNT(*) FROM posts WHERE user_id = :a) AS posts,
            (SELECT COUNT(*) FROM comments WHERE user_id = :b AND status = 'active') AS comments,
            (SELECT COUNT(*) FROM posts WHERE user_id = :c AND is_verified = 1) AS verified",
    ['a' => $me['id'], 'b' => $me['id'], 'c' => $me['id']]
)->fetch();

$pageTitle = 'My profile';
require __DIR__ . '/includes/header.php';
?>
<section class="card profile-hero">
    <div class="cover"></div>
    <div class="profile-id">
        <?= avatar($me['name'], 'xl') ?>
        <div class="profile-name">
            <h1><?= e($me['name']) ?></h1>
            <p class="muted"><a href="people.php?tab=friends"><?= friend_count((int)$me['id']) ?> friends</a> · <?= e(ROLE_LABELS[$me['role']]) ?> · member since <?= e(date('F Y', strtotime($me['created_at']))) ?></p>
        </div>
        <a class="btn btn-primary" href="post_create.php"><?= icon('plus') ?> Create post</a>
    </div>
    <nav class="profile-tabs">
        <a class="active" href="profile.php">Posts</a>
        <a href="people.php?tab=friends">Friends</a>
        <a href="areas.php">Watch areas</a>
        <a href="notifications.php">Notifications</a>
    </nav>
</section>

<div class="profile-layout">
    <aside class="feed-side">
        <div class="card">
            <h2>Intro</h2>
            <div class="stats">
                <div><b><?= (int)$stats['posts'] ?></b><span>posts</span></div>
                <div><b><?= (int)$stats['comments'] ?></b><span>comments</span></div>
                <div><b><?= (int)$stats['verified'] ?></b><span>verified</span></div>
            </div>
        </div>
        <div class="card">
            <h3>Display name</h3>
            <form method="post" class="form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="name">
                <input type="text" name="name" value="<?= e($me['name']) ?>" maxlength="80" required>
                <button class="btn btn-sm" type="submit">Save</button>
            </form>
        </div>
        <div class="card">
            <h3>Change password</h3>
            <form method="post" class="form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="password">
                <input type="password" name="current" placeholder="Current password" required>
                <input type="password" name="new" placeholder="New password (8+ chars)" minlength="8" required>
                <input type="password" name="confirm" placeholder="Confirm new password" minlength="8" required>
                <button class="btn btn-sm" type="submit">Change password</button>
            </form>
        </div>
        <div class="card small muted">
            <h3>Privacy</h3>
            <p>Your exact location is never shown. Posts appear on maps rounded to ~100 m and distances are approximate.
                Anonymous posts hide your name from everyone except moderators.</p>
        </div>
    </aside>
    <div class="feed-main">
        <div class="card section-title"><h2>Posts</h2></div>
        <?php if (!$myPosts): ?>
            <div class="card empty"><p>You haven't posted yet. <a href="post_create.php">Share something with your neighbours</a>.</p></div>
        <?php endif; ?>
        <?php foreach ($myPosts as $post): ?>
            <?php if ($post['status'] !== 'active'): ?><p class="small muted">⚠ Status: <?= e($post['status']) ?></p><?php endif; ?>
            <?php require __DIR__ . '/includes/post_card.php'; ?>
        <?php endforeach; ?>
    </div>

</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
