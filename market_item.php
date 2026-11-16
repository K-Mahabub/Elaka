<?php
/** A single marketplace listing: photos, details, seller contact, questions & comments. */
require __DIR__ . '/includes/bootstrap.php';
$me = require_login();

$item = get_listing((int)($_GET['id'] ?? 0));
if (!$item) {
    http_response_code(404);
    flash('error', 'That listing no longer exists. It may have been sold, expired or removed.');
    redirect('marketplace.php');
}

[$allowed, $reason] = post_access($item);
$postId = (int)$item['id'];
$self = market_url($postId);
$isOwner = (int)$item['user_id'] === (int)$me['id'];
$isMod = has_role('moderator');
$distance = post_distance($item);

$pageTitle = $allowed ? $item['title'] : 'Out of range';
$needsMap = $allowed;

if (!$allowed) {
    require __DIR__ . '/includes/header.php';
    $messages = [
        'removed'      => ['🚫', 'This listing is no longer available', 'It was removed or is under review by moderators.'],
        'expired'      => ['⏳', 'This listing has expired', 'Listings are removed automatically after ' . market_cfg('expiry_days') . ' days unless the seller renews them.'],
        'no_location'  => ['📡', 'Share your location to view this listing', 'The marketplace only shows items from people nearby.'],
        'out_of_range' => ['📍', 'This item is outside your area', 'It is only visible within ' . format_radius((int)$item['radius_m'])
            . ' of the pick-up point. You are about ' . format_distance((float)$distance) . ' away.'],
    ][$reason] ?? ['🚫', 'Unavailable', ''];
    ?>
    <section class="card hero">
        <div class="hero-icon"><?= $messages[0] ?></div>
        <h1><?= e($messages[1]) ?></h1>
        <p><?= e($messages[2]) ?></p>
        <p><a class="btn btn-primary" href="<?= $reason === 'no_location' ? 'location.php' : 'marketplace.php' ?>">
            <?= $reason === 'no_location' ? 'Set my location' : 'Back to the marketplace' ?></a></p>
    </section>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$photos = market_photos($item);
$cat = market_category($item['item_category']);
$expired = post_is_expired($item);
$sold = $item['sale_status'] === 'sold';
$open = $item['status'] === 'active' && !$expired;

$comments = q(
    "SELECT c.*, u.name AS author_name, u.role AS author_role
     FROM comments c JOIN users u ON u.id = c.user_id
     WHERE c.post_id = ? AND (c.status = 'active' OR ?)
     ORDER BY c.created_at ASC",
    [$postId, $isMod ? 1 : 0]
)->fetchAll();
$reactionCounts = q('SELECT type, COUNT(*) AS n FROM reactions WHERE post_id = ? GROUP BY type', [$postId])->fetchAll(PDO::FETCH_KEY_PAIR);

$seller = q(
    "SELECT u.id, u.name, u.role, u.created_at,
            (SELECT COUNT(*) FROM posts p WHERE p.user_id = u.id AND p.category = 'marketplace' AND p.status = 'active' AND p.is_anonymous = 0) AS listing_count
     FROM users u WHERE u.id = ?",
    [$item['user_id']]
)->fetch();
$anonymous = (bool)$item['is_anonymous'];
$contactLinks = $sold ? [] : market_contact_links($item);

$loc = user_location();
$similar = $loc ? fetch_nearby_listings($loc['lat'], $loc['lng'], $loc['radius'], [
    'cat' => $item['item_category'], 'exclude' => $postId, 'status' => 'available', 'limit' => 4,
]) : [];

require __DIR__ . '/includes/header.php';
?>
<p><a href="marketplace.php" class="back-link"><?= icon('back') ?> Marketplace</a></p>

<?php if ($item['status'] !== 'active'): ?>
    <div class="flash flash-warning">This listing is <strong><?= e($item['status']) ?></strong> and not visible to the community<?= $item['status'] === 'hidden' ? ' (auto-hidden after reports, awaiting review)' : '' ?>.</div>
<?php endif; ?>

<div class="post-layout">
    <article class="card post-full mk-full cat-marketplace">
        <?php if ($photos): ?>
            <div class="pet-gallery mk-gallery" data-gallery>
                <img class="pet-gallery-main" src="<?= e(image_url($photos[0]['filename'])) ?>" alt="Photo of <?= e($item['title']) ?>" data-gallery-main>
                <?php if (count($photos) > 1): ?>
                    <div class="pet-gallery-thumbs">
                        <?php foreach ($photos as $i => $ph): ?>
                            <button type="button" class="<?= $i === 0 ? 'on' : '' ?>" data-gallery-src="<?= e(image_url($ph['filename'])) ?>" aria-label="Photo <?= $i + 1 ?>">
                                <img src="<?= e(image_url($ph['filename'])) ?>" alt="" loading="lazy">
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="pet-nophoto mk-nophoto" aria-hidden="true"><?= $cat['icon'] ?></div>
        <?php endif; ?>

        <div class="mk-badges">
            <?= market_status_badge($item['sale_status']) ?>
            <?= market_type_badge($item['subtype']) ?>
            <span class="sub-tag"><?= $cat['icon'] ?> <?= e($cat['label']) ?></span>
        </div>
        <h1 class="post-title"><?= e($item['title']) ?></h1>
        <div class="mk-price mk-price-lg"><?= e(market_price_label($item)) ?>
            <?php if ($item['negotiable'] && $item['price'] !== null && (float)$item['price'] > 0): ?><small class="muted">· negotiable</small><?php endif; ?>
        </div>

        <dl class="facts">
            <?php if ($item['item_condition']): ?><dt>🏷 Condition</dt><dd><?= e(market_conditions()[$item['item_condition']]) ?></dd><?php endif; ?>
            <dt>📍 Pick-up</dt>
            <dd><?= $item['location_text'] ? e($item['location_text']) . ' · ' : '' ?><?= $distance !== null ? e(format_distance($distance)) . ' from you' : 'nearby' ?></dd>
            <dt>🕒 Listed</dt>
            <dd><?= e(time_ago($item['created_at'])) ?>
                <?php if ($item['expires_at']): ?> · ⏳ <?= $expired ? 'expired' : 'expires ' . e(time_until($item['expires_at'])) ?><?php endif; ?></dd>
            <?php if (!$item['has_listing'] && $item['contact']): ?><dt>📞 Contact</dt><dd><?= e($item['contact']) ?></dd><?php endif; ?>
        </dl>

        <?php if ($item['body']): ?>
            <h2 class="mk-section-title">Description</h2>
            <div class="post-body"><?= render_text($item['body']) ?></div>
        <?php endif; ?>

        <div class="reactions">
            <?php foreach (reaction_types() as $type => $r): ?>
                <form method="post" action="actions.php" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="react">
                    <input type="hidden" name="post_id" value="<?= $postId ?>">
                    <input type="hidden" name="type" value="<?= e($type) ?>">
                    <input type="hidden" name="return" value="<?= e($self) ?>">
                    <button type="submit" class="react-btn<?= $item['my_reaction'] === $type ? ' on' : '' ?>" title="<?= e($r['label']) ?>"<?= $open ? '' : ' disabled' ?>>
                        <?= $r['icon'] ?> <span><?= (int)($reactionCounts[$type] ?? 0) ?></span>
                    </button>
                </form>
            <?php endforeach; ?>
        </div>

        <div class="post-actions">
            <?php if (!$isOwner): ?>
                <details class="report-box">
                    <summary class="btn btn-sm">⚑ Report listing</summary>
                    <form method="post" action="actions.php" class="form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="report">
                        <input type="hidden" name="post_id" value="<?= $postId ?>">
                        <input type="hidden" name="return" value="<?= e($self) ?>">
                        <select name="reason" required>
                            <?php foreach (report_reasons() as $k => $l): ?><option value="<?= e($k) ?>" <?= $k === 'scam' ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                        </select>
                        <textarea name="details" rows="2" maxlength="500" placeholder="What is wrong with this listing? (optional)"></textarea>
                        <button class="btn btn-sm btn-danger" type="submit">Send report</button>
                    </form>
                </details>
            <?php endif; ?>
            <?php if ($isMod && !$isOwner && $item['status'] !== 'removed'): ?>
                <form method="post" action="market_actions.php" class="inline" data-confirm="Remove this listing from the marketplace?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="post_id" value="<?= $postId ?>">
                    <button class="btn btn-sm btn-danger" type="submit">Remove (moderator)</button>
                </form>
            <?php endif; ?>
        </div>
    </article>

    <aside>
        <?php if ($isOwner): ?>
            <section class="card mk-manage">
                <h2>Manage your listing</h2>
                <?php if ($item['status'] === 'active'): ?>
                    <p class="small muted">Status</p>
                    <div class="mk-status-actions">
                        <?php foreach (market_statuses() as $k => $s): ?>
                            <form method="post" action="market_actions.php" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="set_status">
                                <input type="hidden" name="post_id" value="<?= $postId ?>">
                                <input type="hidden" name="status" value="<?= e($k) ?>">
                                <button class="btn btn-sm<?= $item['sale_status'] === $k ? ' btn-primary' : '' ?>" type="submit" title="<?= e($s['hint']) ?>"<?= $item['sale_status'] === $k ? ' aria-pressed="true"' : '' ?>><?= e($s['label']) ?></button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="mk-status-actions">
                    <a class="btn btn-sm" href="market_sell.php?edit=<?= $postId ?>">✏️ Edit</a>
                    <?php if ($item['status'] === 'active'): ?>
                        <form method="post" action="market_actions.php" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="renew">
                            <input type="hidden" name="post_id" value="<?= $postId ?>">
                            <button class="btn btn-sm" type="submit" title="Keep it up for another <?= market_cfg('expiry_days') ?> days">🔄 Renew</button>
                        </form>
                    <?php endif; ?>
                    <form method="post" action="market_actions.php" class="inline" data-confirm="Delete this listing permanently?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="post_id" value="<?= $postId ?>">
                        <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                    </form>
                </div>
                <?php if (!$item['phone'] && !$item['email']): ?>
                    <p class="small muted">Tip: <a href="market_sell.php?edit=<?= $postId ?>">add a phone number or email</a> so buyers can reach you directly.</p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="card mk-seller">
            <h2>Seller</h2>
            <div class="mk-seller-row">
                <?= avatar($seller['name'], 'md', $anonymous) ?>
                <div>
                    <?php if ($anonymous): ?>
                        <strong><?= e(author_label($item)) ?></strong>
                    <?php else: ?>
                        <strong><a href="<?= $isOwner ? 'profile.php' : 'user.php?id=' . (int)$seller['id'] ?>"><?= e($seller['name']) ?></a></strong>
                        <?php if ($seller['role'] !== 'user'): ?><span class="role-tag role-<?= e($seller['role']) ?>"><?= e(ROLE_LABELS[$seller['role']]) ?></span><?php endif; ?>
                        <div class="small muted">Member since <?= e(date('M Y', strtotime($seller['created_at']))) ?> · <?= (int)$seller['listing_count'] ?> listing<?= (int)$seller['listing_count'] === 1 ? '' : 's' ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($sold): ?>
                <p class="mk-sold-note">This item has been sold.</p>
            <?php elseif (!$isOwner): ?>
                <?php if ($item['sale_status'] === 'reserved'): ?>
                    <p class="small mk-warn">Reserved for another buyer. You can still ask to be next in line.</p>
                <?php endif; ?>
                <div class="mk-contact">
                    <?php foreach ($contactLinks as [$kind, $label, $href]): ?>
                        <a class="btn mk-contact-<?= e($kind) ?>" href="<?= e($href) ?>"<?= $kind === 'whatsapp' ? ' target="_blank" rel="noopener noreferrer"' : '' ?>><?= e($label) ?></a>
                    <?php endforeach; ?>
                    <?php if ($open): ?>
                        <a class="btn<?= $contactLinks ? '' : ' btn-primary' ?>" href="#comment-form">💬 Ask a question</a>
                    <?php endif; ?>
                    <?php if (!$anonymous): ?>
                        <a class="btn" href="user.php?id=<?= (int)$seller['id'] ?>">👤 View profile</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

        <div class="card">
            <div class="map map-sm" data-map='<?= e(json_encode([
                'center' => [fuzz_coord((float)$item['lat']), fuzz_coord((float)$item['lng'])],
                'zoom' => 15,
                'circle' => ['center' => [fuzz_coord((float)$item['lat']), fuzz_coord((float)$item['lng'])], 'radius' => 150, 'color' => '#1877f2'],
                'markers' => [['lat' => fuzz_coord((float)$item['lat']), 'lng' => fuzz_coord((float)$item['lng']), 'icon' => $cat['icon'], 'title' => $item['title']]],
                'me' => $loc ? [$loc['lat'], $loc['lng']] : null,
            ])) ?>'></div>
            <p class="small muted">Approximate pick-up area. Agree on the exact spot with the seller.</p>
        </div>

        <div class="card small mk-tips">
            <h3>Stay safe</h3>
            <ul>
                <li>Meet in a public place and check the item before paying.</li>
                <li>Never pay in advance or share OTP / bKash PINs.</li>
            </ul>
        </div>
    </aside>
</div>

<section class="card comments" id="comments">
    <h2>Questions &amp; comments (<?= count(array_filter($comments, fn($c) => $c['status'] === 'active')) ?>)</h2>
    <p class="small muted">Comments are public to neighbours who can see this listing. Share phone numbers or addresses privately.</p>

    <?php foreach ($comments as $c): ?>
        <div class="comment<?= $c['status'] !== 'active' ? ' removed' : '' ?>" id="c<?= (int)$c['id'] ?>">
            <?= avatar($c['author_name'], 'sm', (bool)$c['is_anonymous']) ?>
            <div class="comment-main">
                <div class="bubble">
                    <div class="comment-head">
                        <strong><?= e(author_label($c)) ?></strong>
                        <?php if ((int)$c['user_id'] === (int)$item['user_id'] && !$anonymous && !$c['is_anonymous']): ?><span class="role-tag">Seller</span><?php endif; ?>
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
                                <input type="hidden" name="return" value="<?= e($self) ?>">
                                <button class="linklike" type="submit">Delete</button>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <form method="post" action="actions.php" class="inline" data-confirm="Report this comment to moderators?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="report">
                            <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
                            <input type="hidden" name="reason" value="inappropriate">
                            <input type="hidden" name="return" value="<?= e($self) ?>">
                            <button class="linklike" type="submit">Report</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (!$comments): ?><p class="muted">No questions yet. Ask the seller about condition, price or pick-up time.</p><?php endif; ?>

    <?php if ($open): ?>
        <form method="post" action="actions.php" class="form comment-form" id="comment-form">
            <?= csrf_field() ?>
            <?= honeypot_field() ?>
            <input type="hidden" name="action" value="comment">
            <input type="hidden" name="post_id" value="<?= $postId ?>">
            <input type="hidden" name="return" value="<?= e($self) ?>">
            <div class="comment-compose">
                <?= avatar($me['name'], 'sm') ?>
                <textarea name="body" rows="2" maxlength="2000" required placeholder="<?= $isOwner ? 'Reply to buyers or add an update…' : 'Is this still available? Can I pick it up this evening?' ?>"></textarea>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit"><?= $isOwner ? 'Post update' : 'Ask seller' ?></button>
            </div>
        </form>
    <?php endif; ?>
</section>

<?php if ($similar): ?>
    <section class="mk-similar">
        <h2>More <?= e(strtolower($cat['label'])) ?> nearby</h2>
        <div class="mk-grid">
            <?php foreach ($similar as $item): ?>
                <?php require __DIR__ . '/includes/market_card.php'; ?>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
<?php
$extraScripts = ['assets/js/pets.js?v=1']; // photo gallery (shared with the pet board)
require __DIR__ . '/includes/footer.php';
