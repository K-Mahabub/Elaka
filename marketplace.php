<?php
/** Community Marketplace: browse items for sale from people nearby, or manage your own listings. */
require __DIR__ . '/includes/bootstrap.php';
$me = require_login();

// Radius switch from the toolbar (same session setting as the main feed).
if (isset($_GET['radius']) && valid_viewer_radius((int)$_GET['radius'])) {
    $_SESSION['radius'] = (int)$_GET['radius'];
}
$loc = user_location();

$num = fn(string $k) => isset($_GET[$k]) && is_numeric($_GET[$k]) && (float)$_GET[$k] >= 0 ? (string)(float)$_GET[$k] : '';
$flt = [
    'q'      => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100),
    'cat'    => isset(market_categories()[$_GET['cat'] ?? '']) ? $_GET['cat'] : '',
    'type'   => isset(market_types()[$_GET['type'] ?? '']) ? $_GET['type'] : '',
    'cond'   => isset(market_conditions()[$_GET['cond'] ?? '']) ? $_GET['cond'] : '',
    'min'    => $num('min'),
    'max'    => $num('max'),
    'status' => in_array($_GET['status'] ?? '', ['open', 'all', 'available', 'sold'], true) ? $_GET['status'] : 'open',
    'photos' => !empty($_GET['photos']) ? '1' : '',
    'sort'   => in_array($_GET['sort'] ?? '', ['new', 'near', 'price_asc', 'price_desc'], true) ? $_GET['sort'] : 'new',
    'view'   => ($_GET['view'] ?? '') === 'map' ? 'map' : 'grid',
];
if ($flt['min'] !== '' && $flt['max'] !== '' && (float)$flt['min'] > (float)$flt['max']) {
    [$flt['min'], $flt['max']] = [$flt['max'], $flt['min']];
}
$mine = !empty($_GET['mine']);

$items = [];
if ($mine) {
    $items = fetch_my_listings((int)$me['id']);
} elseif ($loc) {
    $items = fetch_nearby_listings($loc['lat'], $loc['lng'], $loc['radius'], $flt);
}

function market_list_url(array $override): string
{
    $keep = ['q', 'cat', 'type', 'cond', 'min', 'max', 'status', 'photos', 'sort', 'view'];
    $params = [];
    foreach ($keep as $k) {
        $params[$k] = $_GET[$k] ?? '';
    }
    $params = array_filter(array_merge($params, $override), fn($v) => $v !== '' && $v !== null);
    if (($params['status'] ?? '') === 'open') {
        unset($params['status']);
    }
    return 'marketplace.php' . ($params ? '?' . http_build_query($params) : '');
}

$activeFilters = array_filter([$flt['q'], $flt['type'], $flt['cond'], $flt['min'], $flt['max'], $flt['photos'], $flt['status'] !== 'open' ? 1 : '']);

$pageTitle = $mine ? 'My listings' : 'Marketplace';
$needsMap = $loc && !$mine;
require __DIR__ . '/includes/header.php';
?>
<section class="page-head row mk-head">
    <div>
        <h1>🛒 <?= $mine ? 'My listings' : 'Marketplace' ?></h1>
        <p class="muted"><?= $mine
            ? 'Everything you have listed. Mark items reserved or sold, renew them, or edit the details.'
            : 'Buy, sell, swap and give away with neighbours nearby. Pick up locally, no shipping.' ?></p>
    </div>
    <div class="mk-head-actions">
        <?php if ($mine): ?>
            <a class="btn" href="marketplace.php">Browse nearby</a>
        <?php else: ?>
            <a class="btn" href="marketplace.php?mine=1">My listings</a>
        <?php endif; ?>
        <a class="btn btn-primary" href="market_sell.php"><?= icon('plus') ?> Sell something</a>
    </div>
</section>

<?php if ($mine): ?>
    <?php if (!$items): ?>
        <div class="card empty">
            <p>📦 You have not listed anything yet.</p>
            <p><a class="btn btn-primary" href="market_sell.php">Create your first listing</a></p>
        </div>
    <?php else: ?>
        <div class="mk-grid">
            <?php $mcShowOwner = true; foreach ($items as $item): ?>
                <?php require __DIR__ . '/includes/market_card.php'; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php elseif (!$loc): ?>
    <section class="card hero">
        <div class="hero-icon">📍</div>
        <h1>Share your location to see items nearby</h1>
        <p>The marketplace shows listings from neighbours within your chosen radius.</p>
        <button class="btn btn-primary btn-lg" type="button" data-locate>📡 Use my current location</button>
        <?php if (config('allow_manual_location')): ?><p><a href="location.php">or pick a place on the map</a></p><?php endif; ?>
        <p class="muted small" data-locate-status></p>
    </section>

<?php else: ?>
    <nav class="tabs mk-cats" aria-label="Categories">
        <a href="<?= e(market_list_url(['cat' => ''])) ?>" class="<?= $flt['cat'] === '' ? 'active' : '' ?>">All categories</a>
        <?php foreach (market_categories() as $k => $c): ?>
            <a href="<?= e(market_list_url(['cat' => $k])) ?>" class="<?= $flt['cat'] === $k ? 'active' : '' ?>"><?= $c['icon'] ?> <?= e($c['label']) ?></a>
        <?php endforeach; ?>
    </nav>

    <form class="card toolbar mk-filters" method="get" id="marketFilters">
        <?php if ($flt['cat']): ?><input type="hidden" name="cat" value="<?= e($flt['cat']) ?>"><?php endif; ?>
        <?php if ($flt['view'] === 'map'): ?><input type="hidden" name="view" value="map"><?php endif; ?>
        <input type="search" name="q" value="<?= e($flt['q']) ?>" placeholder="Search items, e.g. bicycle, sofa, iPhone…" aria-label="Search listings">
        <select name="sort" aria-label="Sort" onchange="this.form.submit()">
            <option value="new" <?= $flt['sort'] === 'new' ? 'selected' : '' ?>>Newest first</option>
            <option value="near" <?= $flt['sort'] === 'near' ? 'selected' : '' ?>>Nearest first</option>
            <option value="price_asc" <?= $flt['sort'] === 'price_asc' ? 'selected' : '' ?>>Price: low to high</option>
            <option value="price_desc" <?= $flt['sort'] === 'price_desc' ? 'selected' : '' ?>>Price: high to low</option>
        </select>
        <select name="radius" aria-label="Radius" onchange="this.form.submit()">
            <?php
            $radiusChoices = config('radius_options');
            if (!in_array($loc['radius'], $radiusChoices, true)) {
                $radiusChoices[] = $loc['radius'];
                sort($radiusChoices);
            }
            foreach ($radiusChoices as $r): ?>
                <option value="<?= $r ?>" <?= $loc['radius'] === $r ? 'selected' : '' ?>>Within <?= e(format_radius($r)) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-primary" type="submit"><?= icon('search') ?> Search</button>

        <details class="mk-more"<?= $activeFilters ? ' open' : '' ?>>
            <summary>Filters<?= $activeFilters ? ' (' . count($activeFilters) . ')' : '' ?></summary>
            <div class="mk-more-grid">
                <label>Listing type
                    <select name="type">
                        <option value="">Any type</option>
                        <?php foreach (market_types() as $k => $l): ?>
                            <option value="<?= e($k) ?>" <?= $flt['type'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Condition
                    <select name="cond">
                        <option value="">Any condition</option>
                        <?php foreach (market_conditions() as $k => $l): ?>
                            <option value="<?= e($k) ?>" <?= $flt['cond'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Min price (৳)
                    <input type="number" name="min" min="0" step="1" value="<?= e($flt['min']) ?>" placeholder="0">
                </label>
                <label>Max price (৳)
                    <input type="number" name="max" min="0" step="1" value="<?= e($flt['max']) ?>" placeholder="Any">
                </label>
                <label>Availability
                    <select name="status">
                        <option value="open" <?= $flt['status'] === 'open' ? 'selected' : '' ?>>Available &amp; reserved</option>
                        <option value="available" <?= $flt['status'] === 'available' ? 'selected' : '' ?>>Available only</option>
                        <option value="sold" <?= $flt['status'] === 'sold' ? 'selected' : '' ?>>Recently sold</option>
                        <option value="all" <?= $flt['status'] === 'all' ? 'selected' : '' ?>>Everything</option>
                    </select>
                </label>
                <label class="check"><input type="checkbox" name="photos" value="1" <?= $flt['photos'] ? 'checked' : '' ?>> <span>With photos only</span></label>
            </div>
            <div class="mk-more-actions">
                <?php if ($activeFilters): ?><a href="<?= e(market_list_url(['q' => '', 'type' => '', 'cond' => '', 'min' => '', 'max' => '', 'status' => '', 'photos' => ''])) ?>">Clear filters</a><?php endif; ?>
                <button class="btn btn-sm" type="submit">Apply filters</button>
            </div>
        </details>
    </form>

    <?php if ($loc['stale']): ?>
        <div class="flash flash-warning">Your location was set a while ago.
            <button class="linklike" type="button" data-locate>Refresh it</button> to see accurate listings.</div>
    <?php endif; ?>

    <div class="mk-resultbar">
        <span class="muted"><?= count($items) ?> listing<?= count($items) === 1 ? '' : 's' ?> within <?= e(format_radius($loc['radius'])) ?><?= $flt['cat'] ? ' in ' . e(market_category($flt['cat'])['label']) : '' ?></span>
        <span class="spacer"></span>
        <nav class="tabs mk-view" aria-label="View">
            <a href="<?= e(market_list_url(['view' => ''])) ?>" class="<?= $flt['view'] === 'grid' ? 'active' : '' ?>">▦ Grid</a>
            <a href="<?= e(market_list_url(['view' => 'map'])) ?>" class="<?= $flt['view'] === 'map' ? 'active' : '' ?>">🗺 Map</a>
        </nav>
    </div>

    <?php if ($flt['view'] === 'map'): ?>
        <div class="card map-card">
            <div class="map map-lg" data-map='<?= e(json_encode([
                'center' => [$loc['lat'], $loc['lng']],
                'circle' => ['center' => [$loc['lat'], $loc['lng']], 'radius' => $loc['radius'], 'color' => '#1877f2'],
                'markers' => array_map(fn($i) => [
                    'lat' => fuzz_coord((float)$i['lat']), 'lng' => fuzz_coord((float)$i['lng']),
                    'icon' => market_category($i['item_category'])['icon'],
                    'cls' => 'mk-pin mk-pin-' . $i['sale_status'],
                    'title' => market_price_label($i) . ' · ' . $i['title'],
                    'url' => market_url((int)$i['id']),
                ], $items),
                'me' => [$loc['lat'], $loc['lng']],
            ])) ?>'></div>
            <p class="small muted">Pins are approximate (about 100 m) to protect sellers' privacy.</p>
        </div>
    <?php endif; ?>

    <?php if (!$items): ?>
        <div class="card empty">
            <p>🛍️ No listings match<?= $flt['cat'] ? ' in ' . e(strtolower(market_category($flt['cat'])['label'])) : '' ?> within <?= e(format_radius($loc['radius'])) ?>.</p>
            <p><a class="btn btn-primary" href="market_sell.php<?= $flt['cat'] ? '?cat=' . e($flt['cat']) : '' ?>">List an item</a>
                <?php if ($loc['radius'] < max(config('radius_options'))): ?>
                    or <a href="<?= e(market_list_url(['radius' => max(config('radius_options'))])) ?>">widen your radius</a>
                <?php endif; ?>
            </p>
        </div>
    <?php else: ?>
        <div class="mk-grid">
            <?php foreach ($items as $item): ?>
                <?php require __DIR__ . '/includes/market_card.php'; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="card small mk-tips">
        <h3>Safe local trading</h3>
        <ul>
            <li>Meet in a public, well-lit place and inspect the item before paying.</li>
            <li>Never send advance payments or share OTP / bKash PINs with anyone.</li>
            <li>Add a <a href="areas.php">watch area</a> with “Marketplace” ticked to hear about new listings near you.</li>
            <li>Spot a scam? Use <strong>⚑ Report</strong> on the listing and moderators will review it.</li>
        </ul>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
