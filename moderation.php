<?php
require __DIR__ . '/includes/bootstrap.php';
$me = require_role('moderator');
$isAdmin = has_role('admin', $me);

$tab = in_array($_GET['tab'] ?? '', ['reports', 'content', 'users'], true) ? $_GET['tab'] : 'reports';

if (is_post()) {
    verify_csrf();
    $action = input('action');
    $id = (int)input('id');

    switch ($action) {
        case 'dismiss_report':
            q("UPDATE reports SET status = 'dismissed', resolved_by = ? WHERE id = ?", [$me['id'], $id]);
            flash('success', 'Report dismissed.');
            break;

        case 'remove_target':
            $r = q('SELECT * FROM reports WHERE id = ?', [$id])->fetch();
            if ($r && $r['comment_id']) {
                q("UPDATE comments SET status = 'removed' WHERE id = ?", [$r['comment_id']]);
                q("UPDATE reports SET status = 'resolved', resolved_by = ? WHERE comment_id = ? AND status = 'open'", [$me['id'], $r['comment_id']]);
                flash('success', 'Comment removed.');
            } elseif ($r && $r['post_id']) {
                $p = q('SELECT user_id, title FROM posts WHERE id = ?', [$r['post_id']])->fetch();
                q("UPDATE posts SET status = 'removed' WHERE id = ?", [$r['post_id']]);
                q("UPDATE reports SET status = 'resolved', resolved_by = ? WHERE post_id = ? AND comment_id IS NULL AND status = 'open'", [$me['id'], $r['post_id']]);
                if ($p) {
                    notify((int)$p['user_id'], (int)$r['post_id'], 'removed', "🚫 Your post “{$p['title']}” was removed for breaking community rules.");
                }
                flash('success', 'Post removed.');
            }
            break;

        case 'set_post_status':
            $status = input('status');
            if (in_array($status, ['active', 'hidden', 'removed'], true)) {
                q('UPDATE posts SET status = ? WHERE id = ?', [$status, $id]);
                if ($status === 'active') {
                    q("UPDATE reports SET status = 'dismissed', resolved_by = ? WHERE post_id = ? AND comment_id IS NULL AND status = 'open'", [$me['id'], $id]);
                }
                flash('success', 'Post status set to ' . $status . '.');
            }
            break;

        case 'ban':
        case 'unban':
            $target = q('SELECT * FROM users WHERE id = ?', [$id])->fetch();
            if (!$target || (int)$target['id'] === (int)$me['id'] || (ROLE_RANK[$target['role']] >= ROLE_RANK[$me['role']])) {
                flash('error', 'You cannot change this account.');
                break;
            }
            q('UPDATE users SET is_banned = ? WHERE id = ?', [$action === 'ban' ? 1 : 0, $id]);
            if ($action === 'ban') {
                q("UPDATE posts SET status = 'hidden' WHERE user_id = ? AND status = 'active'", [$id]);
            }
            flash('success', $action === 'ban' ? 'User suspended and their posts hidden.' : 'User reinstated.');
            break;

        case 'set_role':
            $role = input('role');
            if (!$isAdmin || !isset(ROLE_RANK[$role]) || $id === (int)$me['id']) {
                flash('error', 'Only admins can change roles (and not their own).');
                break;
            }
            q('UPDATE users SET role = ? WHERE id = ?', [$role, $id]);
            flash('success', 'Role updated.');
            break;
    }
    redirect('moderation.php?tab=' . $tab . (isset($_GET['q']) ? '&q=' . urlencode((string)$_GET['q']) : ''));
}

$counts = [
    'reports' => (int)q("SELECT COUNT(*) FROM reports WHERE status = 'open'")->fetchColumn(),
    'content' => (int)q("SELECT COUNT(*) FROM posts WHERE status <> 'active'")->fetchColumn(),
];

$pageTitle = 'Moderation';
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
    <h1>🛡 Safety &amp; moderation</h1>
    <p class="muted">Review reports, restore or remove content and manage members.</p>
</section>

<nav class="tabs">
    <a href="?tab=reports" class="<?= $tab === 'reports' ? 'active' : '' ?>">Open reports <span class="badge"><?= $counts['reports'] ?></span></a>
    <a href="?tab=content" class="<?= $tab === 'content' ? 'active' : '' ?>">Hidden / removed <span class="badge"><?= $counts['content'] ?></span></a>
    <a href="?tab=users" class="<?= $tab === 'users' ? 'active' : '' ?>">Users</a>
</nav>

<?php if ($tab === 'reports'):
    $reports = q(
        "SELECT r.*, u.name AS reporter, p.title AS post_title, p.status AS post_status,
                c.body AS comment_body, c.status AS comment_status,
                (SELECT COUNT(*) FROM reports r2 WHERE r2.status = 'open'
                    AND ((r.comment_id IS NOT NULL AND r2.comment_id = r.comment_id)
                      OR (r.comment_id IS NULL AND r2.comment_id IS NULL AND r2.post_id = r.post_id))) AS same_target
         FROM reports r
         JOIN users u ON u.id = r.user_id
         LEFT JOIN posts p ON p.id = r.post_id
         LEFT JOIN comments c ON c.id = r.comment_id
         WHERE r.status = 'open'
         ORDER BY same_target DESC, r.created_at ASC
         LIMIT 100"
    )->fetchAll();
    ?>
    <div class="card list">
        <?php if (!$reports): ?><p class="muted">🎉 No open reports.</p><?php endif; ?>
        <?php foreach ($reports as $r): ?>
            <div class="mod-row">
                <div class="mod-main">
                    <span class="cat-tag"><?= $r['comment_id'] ? 'Comment' : 'Post' ?></span>
                    <strong><?= e(report_reasons()[$r['reason']] ?? $r['reason']) ?></strong>
                    <?php if ($r['same_target'] > 1): ?><span class="badge"><?= (int)$r['same_target'] ?> reports</span><?php endif; ?>
                    <div>
                        <a href="post.php?id=<?= (int)$r['post_id'] ?><?= $r['comment_id'] ? '#c' . (int)$r['comment_id'] : '' ?>"><?= e($r['post_title']) ?></a>
                        <span class="muted small">(<?= e($r['comment_id'] ? $r['comment_status'] : $r['post_status']) ?>)</span>
                    </div>
                    <?php if ($r['comment_body']): ?><blockquote><?= e(excerpt($r['comment_body'], 200)) ?></blockquote><?php endif; ?>
                    <?php if ($r['details']): ?><p class="small">“<?= e($r['details']) ?>”</p><?php endif; ?>
                    <p class="muted small">Reported by <?= e($r['reporter']) ?> · <?= e(time_ago($r['created_at'])) ?></p>
                </div>
                <div class="mod-actions">
                    <form method="post"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="remove_target"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn-sm btn-danger" type="submit">Remove <?= $r['comment_id'] ? 'comment' : 'post' ?></button>
                    </form>
                    <form method="post"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="dismiss_report"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn-sm" type="submit">Dismiss</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

<?php elseif ($tab === 'content'):
    $posts = q(
        "SELECT p.*, u.name AS author_name,
                (SELECT COUNT(*) FROM reports r WHERE r.post_id = p.id AND r.comment_id IS NULL) AS report_count
         FROM posts p JOIN users u ON u.id = p.user_id
         WHERE p.status <> 'active' ORDER BY p.created_at DESC LIMIT 100"
    )->fetchAll();
    ?>
    <div class="card list">
        <?php if (!$posts): ?><p class="muted">No hidden or removed posts.</p><?php endif; ?>
        <?php foreach ($posts as $p): ?>
            <div class="mod-row">
                <div class="mod-main">
                    <span class="cat-tag cat-<?= e($p['category']) ?>"><?= category($p['category'])['icon'] ?> <?= e(category($p['category'])['label']) ?></span>
                    <span class="sub-tag"><?= e($p['status']) ?></span>
                    <div><a href="post.php?id=<?= (int)$p['id'] ?>"><?= e($p['title']) ?></a></div>
                    <p class="muted small">By <?= e($p['author_name']) ?><?= $p['is_anonymous'] ? ' (posted anonymously)' : '' ?>
                        · <?= e(time_ago($p['created_at'])) ?> · <?= (int)$p['report_count'] ?> report(s)</p>
                </div>
                <div class="mod-actions">
                    <?php foreach (['active' => 'Restore', 'hidden' => 'Hide', 'removed' => 'Remove'] as $s => $label): if ($s === $p['status']) continue; ?>
                        <form method="post"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="set_post_status"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <input type="hidden" name="status" value="<?= $s ?>">
                            <button class="btn btn-sm<?= $s === 'active' ? ' btn-success' : '' ?>" type="submit"><?= $label ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

<?php else:
    $search = trim((string)($_GET['q'] ?? ''));
    $users = q(
        "SELECT u.*,
                (SELECT COUNT(*) FROM posts p WHERE p.user_id = u.id) AS post_count,
                (SELECT COUNT(*) FROM reports r JOIN posts p ON p.id = r.post_id WHERE p.user_id = u.id AND r.comment_id IS NULL) AS reports_against
         FROM users u
         WHERE (? = '' OR u.name LIKE ? OR u.email LIKE ?)
         ORDER BY reports_against DESC, u.created_at DESC LIMIT 100",
        [$search, "%$search%", "%$search%"]
    )->fetchAll();
    ?>
    <form class="toolbar" method="get">
        <input type="hidden" name="tab" value="users">
        <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search name or email">
        <button class="btn" type="submit">Search</button>
    </form>
    <div class="card table-wrap">
        <table class="table">
            <thead><tr><th>User</th><th>Role</th><th>Posts</th><th>Reports</th><th>Joined</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): $canManage = (int)$u['id'] !== (int)$me['id'] && ROLE_RANK[$u['role']] < ROLE_RANK[$me['role']]; ?>
                <tr class="<?= $u['is_banned'] ? 'banned' : '' ?>">
                    <td><strong><?= e($u['name']) ?></strong><br><span class="muted small"><?= e($u['email']) ?></span>
                        <?= $u['is_banned'] ? '<span class="sub-tag">suspended</span>' : '' ?></td>
                    <td>
                        <?php if ($isAdmin && (int)$u['id'] !== (int)$me['id']): ?>
                            <form method="post" class="inline"><?= csrf_field() ?>
                                <input type="hidden" name="action" value="set_role"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <select name="role" onchange="this.form.submit()">
                                    <?php foreach (ROLE_LABELS as $rk => $rl): ?><option value="<?= $rk ?>" <?= $u['role'] === $rk ? 'selected' : '' ?>><?= e($rl) ?></option><?php endforeach; ?>
                                </select>
                            </form>
                        <?php else: ?>
                            <?= e(ROLE_LABELS[$u['role']]) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= (int)$u['post_count'] ?></td>
                    <td><?= (int)$u['reports_against'] ?></td>
                    <td class="small"><?= e(date('j M Y', strtotime($u['created_at']))) ?></td>
                    <td>
                        <?php if ($canManage): ?>
                            <form method="post" class="inline" data-confirm="<?= $u['is_banned'] ? 'Reinstate this user?' : 'Suspend this user and hide their posts?' ?>"><?= csrf_field() ?>
                                <input type="hidden" name="action" value="<?= $u['is_banned'] ? 'unban' : 'ban' ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button class="btn btn-sm <?= $u['is_banned'] ? '' : 'btn-danger' ?>" type="submit"><?= $u['is_banned'] ? 'Unban' : 'Ban' ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
