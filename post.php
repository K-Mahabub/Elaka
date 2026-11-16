<?php
require __DIR__ . '/includes/bootstrap.php';
$me = require_login();

$post = get_post((int)($_GET['id'] ?? 0));
if (!$post) {
    http_response_code(404);
    flash('error', 'That post no longer exists — it may have expired or been removed.');
    redirect('index.php');
}
if ($post['category'] === 'lost_found' && is_pet_post((int)$post['id'])) {
    redirect(pet_url((int)$post['id'])); // pet reports have their own page
}
if ($post['category'] === 'marketplace') {
    redirect(market_url((int)$post['id'])); // marketplace listings have their own page
}

[$allowed, $reason] = post_access($post);
$cat = category($post['category']);
$isAuthor = (int)$post['user_id'] === (int)$me['id'];
$isMod = has_role('moderator');
$distance = post_distance($post);
$self = 'post.php?id=' . (int)$post['id'];

$pageTitle = $allowed ? $post['title'] : 'Out of range';
$needsMap = $allowed;

if (!$allowed) {
    require __DIR__ . '/includes/header.php';
    $messages = [
        'removed'      => ['🚫', 'This post is no longer available', 'It was removed or is under review by moderators.'],
        'expired'      => ['⏳', 'This post has expired', 'Temporary posts are removed automatically once they are out of date.'],
        'no_location'  => ['📡', 'Share your location to view this post', 'Posts on Elaka are only visible to people nearby.'],
        'out_of_range' => ['📍', 'You are outside this post\'s area', 'This post is only visible within ' . format_radius((int)$post['radius_m'])
            . ' of where it was made. You are about ' . format_distance((float)$distance) . ' away.'],
    ][$reason] ?? ['🚫', 'Unavailable', ''];
    ?>
    <section class="card hero">
        <div class="hero-icon"><?= $messages[0] ?></div>
        <h1><?= e($messages[1]) ?></h1>
        <p><?= e($messages[2]) ?></p>
        <p><a class="btn btn-primary" href="<?= $reason === 'no_location' ? 'location.php' : 'index.php' ?>">
            <?= $reason === 'no_location' ? 'Set my location' : 'Back to nearby posts' ?></a></p>
    </section>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$comments = q(
    "SELECT c.*, u.name AS author_name, u.role AS author_role
     FROM comments c JOIN users u ON u.id = c.user_id
     WHERE c.post_id = ? AND (c.status = 'active' OR ?)
     ORDER BY c.created_at ASC",
    [$post['id'], $isMod ? 1 : 0]
)->fetchAll();

$reactionCounts = q('SELECT type, COUNT(*) AS n FROM reactions WHERE post_id = ? GROUP BY type', [$post['id']])->fetchAll(PDO::FETCH_KEY_PAIR);
$poll = $post['category'] === 'poll' ? poll_results((int)$post['id']) : null;
$expired = post_is_expired($post);

require __DIR__ . '/includes/header.php';
?>
<p><a href="index.php" class="back-link"><?= icon('back') ?> Nearby feed</a></p>

<?php if ($post['status'] !== 'active'): ?>
    <div class="flash flash-warning">This post is <strong><?= e($post['status']) ?></strong> and not visible to the community<?= $post['status'] === 'hidden' ? ' (auto-hidden after reports, awaiting review)' : '' ?>.</div>
<?php endif; ?>

<div class="post-layout">
    <article class="card post-full cat-<?= e($post['category']) ?>">
        <header class="post-head">
            <?= avatar($post['author_name'], '', (bool)$post['is_anonymous']) ?>
            <div class="post-who">
                <div class="post-author">
                    <strong><?= e(author_label($post)) ?></strong>
                    <?php if (!$post['is_anonymous'] && $post['author_role'] !== 'user'): ?>
                        <span class="role-tag role-<?= e($post['author_role']) ?>"><?= e(ROLE_LABELS[$post['author_role']]) ?></span>
                    <?php endif; ?>
                </div>
                <div class="post-sub">
                    <?= e(time_ago($post['created_at'])) ?>
                    <?php if ($distance !== null): ?> · <?= e(format_distance($distance)) ?> away<?php endif; ?>
                    · visible within <?= e(format_radius((int)$post['radius_m'])) ?>
                    <?php if ($post['expires_at']): ?> · ⏳ <?= $expired ? 'expired' : 'expires ' . e(time_until($post['expires_at'])) ?><?php endif; ?>
                    · <?= icon('globe') ?>
                </div>
            </div>
        </header>

        <div class="post-meta">
            <span class="cat-tag cat-<?= e($post['category']) ?>"><?= $cat['icon'] ?> <?= e($cat['label']) ?></span>
            <?php if ($post['subtype'] && isset($cat['subtypes'][$post['subtype']])): ?>
                <span class="sub-tag"><?= e($cat['subtypes'][$post['subtype']]) ?></span>
            <?php endif; ?>
            <?php if ($post['is_verified']): ?>
                <span class="verified" title="Verified by <?= e($post['verifier_name'] ?? 'a moderator') ?>">✔ Verified local info</span>
            <?php endif; ?>
        </div>

        <h1 class="post-title"><?= e($post['title']) ?></h1>

        <dl class="facts">
            <?php if ($post['event_start']): ?>
                <dt>🗓 When</dt><dd><?= e(format_datetime($post['event_start'])) ?><?= $post['event_end'] ? ' — ' . e(format_datetime($post['event_end'])) : '' ?></dd>
            <?php endif; ?>
            <?php if ($post['price'] !== null): ?><dt>💰 Price</dt><dd class="price">৳ <?= e(number_format((float)$post['price'], 2)) ?></dd><?php endif; ?>
            <?php if ($post['location_text']): ?><dt>🏠 <?= $post['category'] === 'event' ? 'Venue' : 'Where' ?></dt><dd><?= e($post['location_text']) ?></dd><?php endif; ?>
            <?php if ($post['business_hours']): ?><dt>🕒 Hours</dt><dd><?= e($post['business_hours']) ?></dd><?php endif; ?>
            <?php if ($post['contact']): ?><dt>📞 Contact</dt><dd><?= e($post['contact']) ?></dd><?php endif; ?>
        </dl>

        <?php if ($post['image']): ?>
            <img class="post-image" src="<?= e(image_url($post['image'])) ?>" alt="">
        <?php endif; ?>

        <?php if ($post['body']): ?><div class="post-body"><?= render_text($post['body']) ?></div><?php endif; ?>

        <?php if ($poll): ?>
            <section class="poll">
                <?php $showResults = $poll['mine'] !== null || $expired || $isAuthor; ?>
                <form method="post" action="actions.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="vote">
                    <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                    <?php foreach ($poll['options'] as $o):
                        $pct = $poll['total'] ? round($o['votes'] * 100 / $poll['total']) : 0; ?>
                        <label class="poll-option<?= $poll['mine'] === (int)$o['id'] ? ' mine' : '' ?>">
                            <?php if (!$expired): ?>
                                <input type="radio" name="option_id" value="<?= (int)$o['id'] ?>" <?= $poll['mine'] === (int)$o['id'] ? 'checked' : '' ?> required>
                            <?php endif; ?>
                            <span class="poll-label"><?= e($o['label']) ?></span>
                            <?php if ($showResults): ?>
                                <span class="poll-bar" style="width: <?= $pct ?>%"></span>
                                <span class="poll-pct"><?= $pct ?>% · <?= (int)$o['votes'] ?></span>
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                    <p class="muted small"><?= $poll['total'] ?> vote<?= $poll['total'] === 1 ? '' : 's' ?>
                        <?= $showResults ? '' : '· vote to see results' ?></p>
                    <?php if (!$expired): ?>
                        <button class="btn btn-primary btn-sm" type="submit"><?= $poll['mine'] ? 'Change vote' : 'Vote' ?></button>
                    <?php endif; ?>
                </form>
            </section>
        <?php endif; ?>

        <div class="reactions">
            <?php foreach (reaction_types() as $type => $r): ?>
                <form method="post" action="actions.php" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="react">
                    <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                    <input type="hidden" name="type" value="<?= e($type) ?>">
                    <button type="submit" class="react-btn<?= $post['my_reaction'] === $type ? ' on' : '' ?>" title="<?= e($r['label']) ?>">
                        <?= $r['icon'] ?> <span><?= (int)($reactionCounts[$type] ?? 0) ?></span>
                    </button>
                </form>
            <?php endforeach; ?>
        </div>

        <div class="post-actions">
            <?php if (has_role('trusted') && !$isAuthor && !$post['is_verified']): ?>
                <form method="post" action="actions.php" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="verify">
                    <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                    <button class="btn btn-sm btn-success" type="submit">✔ Verify this information</button>
                </form>
            <?php elseif ($isMod && $post['is_verified']): ?>
                <form method="post" action="actions.php" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="unverify">
                    <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                    <button class="btn btn-sm" type="submit">Remove verification</button>
                </form>
            <?php endif; ?>

            <?php if ($isAuthor || $isMod): ?>
                <form method="post" action="actions.php" class="inline" data-confirm="Delete this post permanently?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_post">
                    <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                    <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                </form>
            <?php endif; ?>

            <?php if (!$isAuthor): ?>
                <details class="report-box">
                    <summary class="btn btn-sm">⚑ Report</summary>
                    <form method="post" action="actions.php" class="form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="report">
                        <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                        <select name="reason" required>
                            <?php foreach (report_reasons() as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?>
                        </select>
                        <textarea name="details" rows="2" maxlength="500" placeholder="Anything moderators should know? (optional)"></textarea>
                        <button class="btn btn-sm btn-danger" type="submit">Send report</button>
                    </form>
                </details>
            <?php endif; ?>
        </div>
    </article>

    <aside>
        <div class="card">
            <div class="map map-sm" data-map='<?= e(json_encode([
                'center' => [fuzz_coord((float)$post['lat']), fuzz_coord((float)$post['lng'])],
                'zoom' => 15,
                'circle' => ['center' => [fuzz_coord((float)$post['lat']), fuzz_coord((float)$post['lng'])], 'radius' => (int)$post['radius_m'], 'color' => '#888'],
                'markers' => [['lat' => fuzz_coord((float)$post['lat']), 'lng' => fuzz_coord((float)$post['lng']), 'icon' => $cat['icon'], 'title' => $post['title']]],
                'me' => user_location() ? [user_location()['lat'], user_location()['lng']] : null,
            ])) ?>'></div>
            <p class="small muted">Approximate area where this was posted (grey circle = who can see it).</p>
        </div>
    </aside>
</div>

<section class="card comments" id="comments">
    <h2>Comments (<?= count(array_filter($comments, fn($c) => $c['status'] === 'active')) ?>)</h2>

    <?php foreach ($comments as $c): ?>
        <div class="comment<?= $c['status'] !== 'active' ? ' removed' : '' ?>" id="c<?= (int)$c['id'] ?>">
            <?= avatar($c['author_name'], 'sm', (bool)$c['is_anonymous']) ?>
            <div class="comment-main">
                <div class="bubble">
                    <div class="comment-head">
                        <strong><?= e(author_label($c)) ?></strong>
                        <?php if ((int)$c['user_id'] === (int)$post['user_id'] && !$post['is_anonymous'] && !$c['is_anonymous']): ?><span class="role-tag">Author</span><?php endif; ?>
                        <?php if (!$c['is_anonymous'] && $c['author_role'] !== 'user'): ?><span class="role-tag role-<?= e($c['author_role']) ?>"><?= e(ROLE_LABELS[$c['author_role']]) ?></span><?php endif; ?>
                    </div>
                    <div class="comment-body"><?= render_text($c['body']) ?></div>
                </div>
                <div class="comment-tools">
                    <span><?= e(time_ago($c['created_at'])) ?><?= $c['status'] !== 'active' ? ' · removed' : '' ?></span>
                    <?php if ((int)$c['user_id'] === (int)$me['id'] || $isMod): ?>
                        <?php if ($c['status'] === 'active'): ?>
                            <form method="post" action="actions.php" class="inline" data-confirm="Delete this comment?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_comment">
                                <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
                                <button class="linklike" type="submit">Delete</button>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <form method="post" action="actions.php" class="inline" data-confirm="Report this comment to moderators?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="report">
                            <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
                            <input type="hidden" name="reason" value="inappropriate">
                            <button class="linklike" type="submit">Report</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (!$comments): ?><p class="muted">No comments yet. Start the conversation.</p><?php endif; ?>

    <?php if (!$expired && $post['status'] === 'active'): ?>
        <form method="post" action="actions.php" class="form comment-form">
            <?= csrf_field() ?>
            <?= honeypot_field() ?>
            <input type="hidden" name="action" value="comment">
            <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
            <div class="comment-compose">
                <?= avatar($me['name'], 'sm') ?>
                <textarea name="body" rows="2" maxlength="2000" required placeholder="Write a comment…"></textarea>
            </div>
            <div class="form-actions">
                <label class="check"><input type="checkbox" name="is_anonymous" value="1"> <span>Comment anonymously</span></label>
                <button class="btn btn-primary" type="submit">Comment</button>
            </div>
        </form>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
