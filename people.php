<?php
/** Find people, see friend requests and your friends list. */
require __DIR__ . '/includes/bootstrap.php';
$me = require_login();
$meId = (int)$me['id'];

$tab = in_array($_GET['tab'] ?? '', ['all', 'requests', 'sent', 'friends'], true) ? $_GET['tab'] : 'all';
$search = trim((string)($_GET['q'] ?? ''));
$return = 'people.php?tab=' . $tab . ($search !== '' ? '&q=' . rawurlencode($search) : '');

if ($tab === 'requests') {
    $people = q(
        "SELECT u.id, u.name, u.role, u.created_at, f.created_at AS since
         FROM friendships f JOIN users u ON u.id = f.requester_id
         WHERE f.addressee_id = ? AND f.status = 'pending' AND u.is_banned = 0
         ORDER BY f.created_at DESC",
        [$meId]
    )->fetchAll();
} elseif ($tab === 'sent') {
    $people = q(
        "SELECT u.id, u.name, u.role, u.created_at, f.created_at AS since
         FROM friendships f JOIN users u ON u.id = f.addressee_id
         WHERE f.requester_id = ? AND f.status = 'pending' AND u.is_banned = 0
         ORDER BY f.created_at DESC",
        [$meId]
    )->fetchAll();
} elseif ($tab === 'friends') {
    $people = friends_of($meId);
} else {
    $people = q(
        "SELECT id, name, role, created_at FROM users
         WHERE id <> ? AND is_banned = 0 AND (? = '' OR name LIKE ?)
         ORDER BY name LIMIT 200",
        [$meId, $search, '%' . $search . '%']
    )->fetchAll();
}

$statuses = friendship_statuses($meId, array_column($people, 'id'));
$pending = pending_request_count($meId);

$pageTitle = 'People';
require __DIR__ . '/includes/header.php';
?>
<section class="page-head row">
    <div>
        <h1>People</h1>
        <p class="muted">Find neighbours, send friend requests and manage your friends.</p>
    </div>
</section>

<nav class="tabs people-tabs">
    <a href="people.php" class="<?= $tab === 'all' ? 'active' : '' ?>">All people</a>
    <a href="people.php?tab=requests" class="<?= $tab === 'requests' ? 'active' : '' ?>">
        Friend requests<?php if ($pending): ?> <span class="badge"><?= $pending ?></span><?php endif; ?></a>
    <a href="people.php?tab=sent" class="<?= $tab === 'sent' ? 'active' : '' ?>">Sent</a>
    <a href="people.php?tab=friends" class="<?= $tab === 'friends' ? 'active' : '' ?>">Your friends (<?= friend_count($meId) ?>)</a>
</nav>

<?php if ($tab === 'all'): ?>
    <form class="toolbar" method="get" action="people.php">
        <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search people by name">
        <button class="btn" type="submit">Search</button>
    </form>
<?php endif; ?>

<?php if (!$people): ?>
    <div class="card empty"><p class="muted">
        <?= match ($tab) {
            'requests' => 'No friend requests right now.',
            'sent'     => 'You have no pending sent requests.',
            'friends'  => 'You have no friends yet. Browse <a href="people.php">All people</a> to add some.',
            default    => 'No one matches your search.',
        } ?>
    </p></div>
<?php else: ?>
    <div class="people-grid">
        <?php foreach ($people as $p): $pid = (int)$p['id']; $mutual = mutual_friend_count($meId, $pid); ?>
            <div class="card person-card">
                <a class="person-cover" href="user.php?id=<?= $pid ?>"><?= avatar($p['name'], 'lg') ?></a>
                <div class="person-body">
                    <a class="person-name" href="user.php?id=<?= $pid ?>"><?= e($p['name']) ?></a>
                    <span class="muted small">
                        <?= e(ROLE_LABELS[$p['role']]) ?>
                        <?php if ($mutual): ?> · <?= $mutual ?> mutual friend<?= $mutual === 1 ? '' : 's' ?><?php endif; ?>
                    </span>
                    <?php if (!empty($p['since'])): ?><span class="muted small"><?= e(time_ago($p['since'])) ?></span><?php endif; ?>
                    <div class="person-actions"><?= friend_buttons($pid, $statuses[$pid] ?? 'none', $return) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
