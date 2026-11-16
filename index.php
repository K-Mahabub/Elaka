<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

// Quick radius switch from the feed toolbar.
if (isset($_GET['radius']) && valid_viewer_radius((int)$_GET['radius'])) {
    $_SESSION['radius'] = (int)$_GET['radius'];
}

$loc = user_location();
$cat = isset($_GET['cat']) && category((string)$_GET['cat']) ? (string)$_GET['cat'] : '';
$sort = in_array($_GET['sort'] ?? '', ['new', 'near', 'top', 'upcoming'], true) ? $_GET['sort'] : ($cat === 'event' ? 'upcoming' : 'new');
$search = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);

$posts = $alerts = [];
if ($loc) {
    $posts = fetch_nearby_posts($loc['lat'], $loc['lng'], $loc['radius'], ['cat' => $cat, 'q' => $search, 'sort' => $sort]);
    if ($cat === '' && $search === '') {
        $alerts = fetch_nearby_posts($loc['lat'], $loc['lng'], $loc['radius'], ['cat' => 'alert', 'limit' => 5]);
    }
}

$markers = array_map(fn($p) => [
    'lat' => fuzz_coord((float)$p['lat']),
    'lng' => fuzz_coord((float)$p['lng']),
    'icon' => category($p['category'])['icon'],
    'title' => $p['title'],
    'url' => 'post.php?id=' . $p['id'],
], $posts);

function feed_url(array $override): string
{
    $params = array_filter(array_merge(
        ['cat' => $_GET['cat'] ?? '', 'sort' => $_GET['sort'] ?? '', 'q' => $_GET['q'] ?? ''],
        $override
    ), fn($v) => $v !== '' && $v !== null);
    return 'index.php' . ($params ? '?' . http_build_query($params) : '');
}

$pageTitle = $cat ? category($cat)['label'] : 'Nearby';
$needsMap = (bool)$loc;
$wideLayout = true;
$me = current_user();
require __DIR__ . '/includes/header.php';
?>

<div class="fb-layout">
    <aside class="fb-left">
        <nav class="side-list">
            <a href="profile.php"><?= avatar($me['name'], 'sm') ?> <span><?= e($me['name']) ?></span></a>
            <a href="<?= e(feed_url(['cat' => '', 'sort' => ''])) ?>" class="<?= $cat === '' ? 'active' : '' ?>"><span class="side-ic ic-blue"><?= icon('home') ?></span> <span>Nearby feed</span></a>
            <a href="notifications.php"><span class="side-ic ic-blue"><?= icon('bell') ?></span> <span>Notifications</span><?php if (unread_notification_count()): ?><span class="badge"><?= unread_notification_count() ?></span><?php endif; ?></a>
            <a href="areas.php"><span class="side-ic ic-blue"><?= icon('radar') ?></span> <span>Watch areas</span></a>
            <a href="marketplace.php"><span class="side-ic ic-blue"><?= icon('shop') ?></span> <span>Marketplace</span></a>
            <a href="pets.php"><span class="side-ic ic-blue"><?= icon('paw') ?></span> <span>Lost pet board</span></a>
            <a href="location.php"><span class="side-ic ic-blue"><?= icon('pin') ?></span> <span>Location &amp; radius</span></a>
            <?php if (has_role('moderator')): ?>
                <a href="moderation.php"><span class="side-ic ic-blue"><?= icon('shield') ?></span> <span>Moderation</span></a>
            <?php endif; ?>
        </nav>
        <hr>
        <h4 class="side-title">Categories</h4>
        <nav class="side-list">
            <?php foreach (categories() as $key => $c): ?>
                <a href="<?= e(feed_url(['cat' => $key, 'sort' => ''])) ?>" class="<?= $cat === $key ? 'active' : '' ?>"><span class="side-ic emoji cat-<?= e($key) ?>"><?= $c['icon'] ?></span> <span><?= e($c['label']) ?></span></a>
            <?php endforeach; ?>
        </nav>
        <p class="side-foot">Privacy · Pins are approximate · <?= e(config('app_name')) ?> © <?= date('Y') ?></p>
    </aside>

    <div class="fb-feed">
    <?php if (!$loc): ?>
        <section class="card hero">
            <div class="hero-icon">📍</div>
            <h1>What's happening around you?</h1>
            <p>Elaka connects you with people <strong>where you are</strong>. Share your location to see local news,
                alerts, events, lost &amp; found, marketplace listings and more within your chosen radius.</p>
            <button class="btn btn-primary btn-lg" type="button" data-locate>📡 Use my current location</button>
            <?php if (config('allow_manual_location')): ?>
                <p><a href="location.php">or pick a place on the map</a></p>
            <?php endif; ?>
            <p class="muted small" data-locate-status></p>
        </section>
    <?php else: ?>
        <div class="card composer">
            <div class="composer-top">
                <?= avatar($me['name']) ?>
                <a class="composer-input" href="post_create.php<?= $cat ? '?cat=' . e($cat) : '' ?>">What's happening near you, <?= e(explode(' ', $me['name'])[0]) ?>?</a>
            </div>
            <div class="composer-actions">
                <a href="post_create.php?cat=alert"><span class="emoji">🚨</span> Alert</a>
                <a href="post_create.php?cat=event"><span class="emoji">📅</span> Event</a>
                <a href="market_sell.php"><span class="emoji">🛒</span> Sell</a>
            </div>
        </div>

        <nav class="tabs" aria-label="Categories">
            <a href="<?= e(feed_url(['cat' => '', 'sort' => ''])) ?>" class="<?= $cat === '' ? 'active' : '' ?>">All</a>
            <?php foreach (categories() as $key => $c): ?>
                <a href="<?= e(feed_url(['cat' => $key, 'sort' => ''])) ?>" class="<?= $cat === $key ? 'active' : '' ?>"><?= $c['icon'] ?> <?= e($c['label']) ?></a>
            <?php endforeach; ?>
        </nav>

        <form class="card toolbar" method="get">
            <?php if ($cat): ?><input type="hidden" name="cat" value="<?= e($cat) ?>"><?php endif; ?>
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search nearby posts…">
            <select name="sort" onchange="this.form.submit()">
                <option value="new" <?= $sort === 'new' ? 'selected' : '' ?>>Newest</option>
                <option value="near" <?= $sort === 'near' ? 'selected' : '' ?>>Nearest</option>
                <option value="top" <?= $sort === 'top' ? 'selected' : '' ?>>Most active</option>
                <?php if ($cat === 'event'): ?>
                    <option value="upcoming" <?= $sort === 'upcoming' ? 'selected' : '' ?>>Upcoming first</option>
                <?php endif; ?>
            </select>
            <select name="radius" onchange="this.form.submit()" title="Viewing radius">
                <?php
                $radiusChoices = config('radius_options');
                if (!in_array($loc['radius'], $radiusChoices, true)) {
                    $radiusChoices[] = $loc['radius']; // keep a custom radius selectable
                    sort($radiusChoices);
                }
                ?>
                <?php foreach ($radiusChoices as $r): ?>
                    <option value="<?= $r ?>" <?= $loc['radius'] === $r ? 'selected' : '' ?>>Within <?= e(format_radius($r)) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn" type="submit">Go</button>
        </form>

        <?php if ($loc['stale']): ?>
            <div class="flash flash-warning">Your location was set a while ago.
                <button class="linklike" type="button" data-locate>Refresh it</button> to see accurate posts.</div>
        <?php endif; ?>

        <?php if ($alerts): ?>
            <section class="card alert-strip">
                <h2>🚨 Active alerts near you</h2>
                <?php foreach ($alerts as $a): ?>
                    <a class="alert-item" href="post.php?id=<?= (int)$a['id'] ?>">
                        <strong><?= e(category('alert')['subtypes'][$a['subtype']] ?? 'Alert') ?>:</strong>
                        <?= e($a['title']) ?>
                        <?php if ($a['is_verified']): ?><span class="verified">✔</span><?php endif; ?>
                        <span class="muted">· <?= e(format_distance((float)$a['distance'])) ?> · <?= e(time_ago($a['created_at'])) ?></span>
                    </a>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <?php if (!$posts): ?>
            <div class="card empty">
                <p>🌱 Nothing <?= $cat ? 'in ' . e(strtolower(category($cat)['label'])) . ' ' : '' ?>within <?= e(format_radius($loc['radius'])) ?> yet.</p>
                <p><a class="btn btn-primary" href="post_create.php<?= $cat ? '?cat=' . e($cat) : '' ?>">Be the first to post</a>
                    <?php if ($loc['radius'] < max(config('radius_options'))): ?>
                        or <a href="<?= e(feed_url(['radius' => max(config('radius_options'))])) ?>">widen your radius</a>
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>

        <?php foreach ($posts as $post): ?>
            <?php require __DIR__ . '/includes/post_card.php'; ?>
        <?php endforeach; ?>
    <?php endif; ?>
    </div>

    <aside class="fb-right">
        <?php if ($loc): ?>
            <div class="sticky">
                <h4 class="side-title">Around you</h4>
                <div class="card map-card">
                    <div class="map map-sm" data-map='<?= e(json_encode([
                        'center' => [$loc['lat'], $loc['lng']],
                        'circle' => ['center' => [$loc['lat'], $loc['lng']], 'radius' => $loc['radius'], 'color' => '#1877f2'],
                        'markers' => $markers,
                        'me' => [$loc['lat'], $loc['lng']],
                    ])) ?>'></div>
                    <p class="small muted"><?= count($posts) ?> post<?= count($posts) === 1 ? '' : 's' ?> within <?= e(format_radius($loc['radius'])) ?>. Pins are approximate.</p>
                </div>
                <hr>
                <h4 class="side-title">Shortcuts</h4>
                <nav class="side-list">
                    <a href="post_create.php<?= $cat ? '?cat=' . e($cat) : '' ?>"><span class="side-ic ic-grey"><?= icon('plus') ?></span> <span>Create a post here</span></a>
                    <a href="areas.php"><span class="side-ic ic-grey"><?= icon('bell') ?></span> <span>Get alerts for an area</span></a>
                    <a href="location.php"><span class="side-ic ic-grey"><?= icon('pin') ?></span> <span>Change location</span></a>
                </nav>
            </div>
        <?php endif; ?>
    </aside>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
