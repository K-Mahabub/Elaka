<?php
/** Public profile of another user, with friend request buttons. */
require __DIR__ . '/includes/bootstrap.php';
$me = require_login();
$meId = (int)$me['id'];

$id = (int)($_GET['id'] ?? 0);
if ($id === $meId) {
    redirect('profile.php');
}
$user = q('SELECT id, name, role, is_banned, created_at FROM users WHERE id = ?', [$id])->fetch();
if (!$user || ($user['is_banned'] && !has_role('moderator'))) {
    http_response_code(404);
    flash('error', 'That profile could not be found.');
    redirect('people.php');
}

$status = friendship_status($meId, $id);
$friends = friends_of($id, 9);
$friendTotal = friend_count($id);
$mutual = mutual_friend_count($meId, $id);

$stats = q(
    "SELECT (SELECT COUNT(*) FROM posts WHERE user_id = :a AND status = 'active' AND is_anonymous = 0) AS posts,
            (SELECT COUNT(*) FROM comments WHERE user_id = :b AND status = 'active' AND is_anonymous = 0) AS comments,
            (SELECT COUNT(*) FROM posts WHERE user_id = :c AND is_verified = 1 AND is_anonymous = 0) AS verified",
    ['a' => $id, 'b' => $id, 'c' => $id]
)->fetch();

// Their public (non-anonymous) posts. The usual location rule still applies:
// only posts you could open anyway are listed, so profiles never leak far-away posts.
$candidates = q(
    "SELECT p.*, u.name AS author_name, u.role AS author_role,
            (SELECT COUNT(*) FROM reactions r WHERE r.post_id = p.id) AS reaction_count,
            (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id AND c.status = 'active') AS comment_count,
            (SELECT r.type FROM reactions r WHERE r.post_id = p.id AND r.user_id = ?) AS my_reaction
     FROM posts p JOIN users u ON u.id = p.user_id
     WHERE p.user_id = ? AND p.is_anonymous = 0 AND p.status = 'active'
     ORDER BY p.created_at DESC LIMIT 100",
    [$meId, $id]
)->fetchAll();
$posts = array_values(array_filter($candidates, 'can_access_post'));
$hiddenCount = count($candidates) - count($posts);

$return = 'user.php?id=' . $id;
$pageTitle = $user['name'];
require __DIR__ . '/includes/header.php';
?>
<section class="card profile-hero">
    <div class="cover"></div>
    <div class="profile-id">
        <?= avatar($user['name'], 'xl') ?>
        <div class="profile-name">
            <h1><?= e($user['name']) ?></h1>
            <p class="muted">
                <?= $friendTotal ?> friend<?= $friendTotal === 1 ? '' : 's' ?>
                <?php if ($mutual): ?> · <?= $mutual ?> mutual<?php endif; ?>
                · <?= e(ROLE_LABELS[$user['role']]) ?> · member since <?= e(date('F Y', strtotime($user['created_at']))) ?>
            </p>
            <?php if ($status === 'received'): ?>
                <p class="small"><strong><?= e($user['name']) ?></strong> sent you a friend request.</p>
            <?php endif; ?>
        </div>
        <div class="profile-actions"><?= $user['is_banned'] ? '<span class="sub-tag">suspended</span>' : friend_buttons($id, $status, $return, false) ?></div>
    </div>
    <nav class="profile-tabs">
        <a class="active" href="<?= e($return) ?>">Posts</a>
        <a href="#friends">Friends</a>
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
        <div class="card" id="friends">
            <div class="row-between">
                <h2>Friends</h2>
                <span class="muted small"><?= $friendTotal ?></span>
            </div>
            <?php if (!$friends): ?>
                <p class="muted small">No friends yet.</p>
            <?php else: ?>
                <div class="friend-thumbs">
                    <?php foreach ($friends as $f): ?>
                        <a href="<?= (int)$f['id'] === $meId ? 'profile.php' : 'user.php?id=' . (int)$f['id'] ?>" title="<?= e($f['name']) ?>">
                            <?= avatar($f['name'], 'lg') ?>
                            <span><?= e($f['name']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </aside>
    <div class="feed-main">
        <div class="card section-title"><h2>Posts</h2></div>
        <?php if (!$posts): ?>
            <div class="card empty"><p class="muted">No posts to show.</p></div>
        <?php endif; ?>
        <?php foreach ($posts as $post): ?>
            <?php require __DIR__ . '/includes/post_card.php'; ?>
        <?php endforeach; ?>
        <?php if ($hiddenCount > 0): ?>
            <p class="small muted center"><?= $hiddenCount ?> more post<?= $hiddenCount === 1 ? ' is' : 's are' ?> outside your area and can't be shown.</p>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
