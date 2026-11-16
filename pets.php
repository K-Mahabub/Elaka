<?php
/** Lost Pet Tracking Board: nearby lost & found pet reports as a list and on a map. */
require __DIR__ . '/includes/bootstrap.php';
$me = require_login();

// Radius switch from the toolbar (same session setting as the main feed).
if (isset($_GET['radius']) && valid_viewer_radius((int)$_GET['radius'])) {
    $_SESSION['radius'] = (int)$_GET['radius'];
}
$loc = user_location();

$flt = [
    'q'      => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100),
    'area'   => mb_substr(trim((string)($_GET['area'] ?? '')), 0, 100),
    'kind'   => isset(pet_kinds()[$_GET['kind'] ?? '']) ? $_GET['kind'] : '',
    'type'   => isset(pet_types()[$_GET['type'] ?? '']) ? $_GET['type'] : '',
    'status' => in_array($_GET['status'] ?? '', array_merge(['open', 'all'], array_keys(pet_statuses())), true) ? $_GET['status'] : 'open',
    'sort'   => in_array($_GET['sort'] ?? '', ['new', 'near', 'seen'], true) ? $_GET['sort'] : 'new',
    'view'   => ($_GET['view'] ?? '') === 'map' ? 'map' : 'list',
];

// Optional search centre picked on the map (must stay near you, like everything else on Elaka).
$center = $loc ? [$loc['lat'], $loc['lng']] : null;
$customCenter = false;
if ($loc && valid_coords($_GET['clat'] ?? null, $_GET['clng'] ?? null)) {
    $c = [(float)$_GET['clat'], (float)$_GET['clng']];
    if (haversine($loc['lat'], $loc['lng'], $c[0], $c[1]) <= pet_cfg('max_report_km') * 1000) {
        $center = $c;
        $customCenter = true;
    } else {
        flash('warning', 'You can search up to ' . pet_cfg('max_report_km') . ' km from your location. Showing reports around you instead.');
    }
}

$pets = $center ? fetch_nearby_pets($center[0], $center[1], $loc['radius'], $flt) : [];

$markers = array_map(function (array $p) {
    [$lat, $lng] = pet_map_point($p);
    return [
        'lat' => $lat, 'lng' => $lng,
        'icon' => pet_type($p['pet_type'])['icon'],
        'cls' => 'pet-pin pet-pin-' . $p['subtype'] . ' pet-pin-' . $p['pet_status'],
        'title' => pet_kinds()[$p['subtype']] . ' · ' . ($p['pet_name'] ?: pet_type($p['pet_type'])['label']) . ' · ' . pet_statuses()[$p['pet_status']]['label'],
        'url' => pet_url((int)$p['id']),
    ];
}, $pets);

function pets_url(array $override): string
{
    $keep = ['q', 'area', 'kind', 'type', 'status', 'sort', 'view', 'clat', 'clng'];
    $params = [];
    foreach ($keep as $k) {
        $params[$k] = $_GET[$k] ?? '';
    }
    $params = array_filter(array_merge($params, $override), fn($v) => $v !== '' && $v !== null);
    if (($params['status'] ?? '') === 'open') {
        unset($params['status']);
    }
    return 'pets.php' . ($params ? '?' . http_build_query($params) : '');
}

$counts = ['lost' => 0, 'found' => 0];
foreach ($pets as $p) {
    $counts[$p['subtype']] = ($counts[$p['subtype']] ?? 0) + 1;
}

$pageTitle = 'Lost pet board';
$needsMap = (bool)$loc;
require __DIR__ . '/includes/header.php';
?>
<section class="page-head row pet-head">
    <div>
        <h1>🐾 Lost pet board</h1>
        <p class="muted">Lost and found pets near you. Report a pet, share a sighting and help neighbours bring them home.</p>
    </div>
    <div class="pet-head-actions">
        <a class="btn btn-danger" href="pet_create.php?kind=lost">🐾 I lost a pet</a>
        <a class="btn btn-success" href="pet_create.php?kind=found">🤲 I found a pet</a>
    </div>
</section>

<?php if (!$loc): ?>
    <section class="card hero">
        <div class="hero-icon">📍</div>
        <h1>Share your location to see pets nearby</h1>
        <p>The pet board shows lost and found reports within your chosen radius.</p>
        <button class="btn btn-primary btn-lg" type="button" data-locate>📡 Use my current location</button>
        <?php if (config('allow_manual_location')): ?><p><a href="location.php">or pick a place on the map</a></p><?php endif; ?>
        <p class="muted small" data-locate-status></p>
    </section>
<?php else: ?>

<nav class="tabs" aria-label="Report kind">
    <a href="<?= e(pets_url(['kind' => ''])) ?>" class="<?= $flt['kind'] === '' ? 'active' : '' ?>">All reports</a>
    <a href="<?= e(pets_url(['kind' => 'lost'])) ?>" class="<?= $flt['kind'] === 'lost' ? 'active' : '' ?>">🔴 Lost</a>
    <a href="<?= e(pets_url(['kind' => 'found'])) ?>" class="<?= $flt['kind'] === 'found' ? 'active' : '' ?>">🟢 Found</a>
    <span class="spacer"></span>
    <a href="<?= e(pets_url(['view' => 'list'])) ?>" class="<?= $flt['view'] === 'list' ? 'active' : '' ?>">☰ List</a>
    <a href="<?= e(pets_url(['view' => 'map'])) ?>" class="<?= $flt['view'] === 'map' ? 'active' : '' ?>">🗺 Map</a>
</nav>

<form class="card toolbar pet-filters" method="get" id="petFilters">
    <?php foreach (['kind', 'view'] as $k): if ($flt[$k] !== '' && !($k === 'view' && $flt[$k] === 'list')): ?>
        <input type="hidden" name="<?= $k ?>" value="<?= e($flt[$k]) ?>">
    <?php endif; endforeach; ?>
    <input type="hidden" name="clat" id="f_clat" value="<?= $customCenter ? e($center[0]) : '' ?>">
    <input type="hidden" name="clng" id="f_clng" value="<?= $customCenter ? e($center[1]) : '' ?>">
    <input type="search" name="q" value="<?= e($flt['q']) ?>" placeholder="Name, breed, colour, description…" aria-label="Search pets">
    <input type="search" name="area" value="<?= e($flt['area']) ?>" placeholder="Area / landmark" aria-label="Area or landmark" class="pet-area">
    <select name="type" aria-label="Pet type" onchange="this.form.submit()">
        <option value="">All pets</option>
        <?php foreach (pet_types() as $k => $t): ?>
            <option value="<?= e($k) ?>" <?= $flt['type'] === $k ? 'selected' : '' ?>><?= $t['icon'] ?> <?= e($t['label']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="status" aria-label="Status" onchange="this.form.submit()">
        <option value="open" <?= $flt['status'] === 'open' ? 'selected' : '' ?>>Active &amp; sighted</option>
        <?php foreach (pet_statuses() as $k => $s): ?>
            <option value="<?= e($k) ?>" <?= $flt['status'] === $k ? 'selected' : '' ?>><?= e($s['label']) ?></option>
        <?php endforeach; ?>
        <option value="all" <?= $flt['status'] === 'all' ? 'selected' : '' ?>>Any status</option>
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
    <select name="sort" aria-label="Sort" onchange="this.form.submit()">
        <option value="new" <?= $flt['sort'] === 'new' ? 'selected' : '' ?>>Newest reports</option>
        <option value="seen" <?= $flt['sort'] === 'seen' ? 'selected' : '' ?>>Recently seen</option>
        <option value="near" <?= $flt['sort'] === 'near' ? 'selected' : '' ?>>Nearest</option>
    </select>
    <button class="btn" type="submit">Search</button>
</form>

<?php if ($loc['stale']): ?>
    <div class="flash flash-warning">Your location was set a while ago.
        <button class="linklike" type="button" data-locate>Refresh it</button> to see accurate reports.</div>
<?php endif; ?>

<?php
$mapCfg = [
    'center' => $center,
    'circle' => ['center' => $center, 'radius' => $loc['radius'], 'color' => '#f7923b', 'follow' => true],
    'markers' => $markers,
    'me' => [$loc['lat'], $loc['lng']],
    'picker' => ['lat' => '#f_clat', 'lng' => '#f_clng', 'marker' => $customCenter ? $center : null],
];
$mapNote = function () use ($pets, $counts, $loc, $customCenter) { ?>
    <p class="small muted">
        <?= count($pets) ?> report<?= count($pets) === 1 ? '' : 's' ?> (<?= $counts['lost'] ?> lost · <?= $counts['found'] ?> found)
        within <?= e(format_radius($loc['radius'])) ?> of <?= $customCenter ? 'the pin' : 'you' ?>.
        Tap the map to search around another spot, then press <button class="linklike" type="submit" form="petFilters">Search</button>.
        <?php if ($customCenter): ?><a href="<?= e(pets_url(['clat' => '', 'clng' => ''])) ?>">Back to my location</a><?php endif; ?>
    </p>
<?php }; ?>

<?php if ($flt['view'] === 'map'): ?>
    <div class="card map-card">
        <div class="map map-lg" data-map='<?= e(json_encode($mapCfg)) ?>'></div>
        <?php $mapNote(); ?>
        <p class="small pet-legend"><span class="pet-dot lost"></span> Lost <span class="pet-dot found"></span> Found · faded pins are reunited / closed</p>
    </div>
<?php endif; ?>

<div class="<?= $flt['view'] === 'map' ? '' : 'post-layout' ?>">
    <div>
        <?php if (!$pets): ?>
            <div class="card empty">
                <p>🐾 No <?= $flt['kind'] ? e(strtolower(pet_kinds()[$flt['kind']])) . ' ' : '' ?>pet reports match within <?= e(format_radius($loc['radius'])) ?>.</p>
                <p><a class="btn btn-primary" href="pet_create.php?kind=<?= e($flt['kind'] ?: 'lost') ?>">Report a pet</a>
                    <?php if ($loc['radius'] < max(config('radius_options'))): ?>
                        or <a href="<?= e(pets_url(['radius' => max(config('radius_options'))])) ?>">widen your radius</a>
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>
        <div class="<?= $flt['view'] === 'map' ? 'pet-grid' : 'pet-list' ?>">
            <?php foreach ($pets as $pet): ?>
                <?php require __DIR__ . '/includes/pet_card.php'; ?>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($flt['view'] === 'list'): ?>
        <aside>
            <div class="sticky">
                <div class="card map-card">
                    <div class="map map-sm" data-map='<?= e(json_encode($mapCfg)) ?>'></div>
                    <?php $mapNote(); ?>
                </div>
                <div class="card small">
                    <h3>Tips</h3>
                    <ul class="pet-tips">
                        <li>Search around where the pet was last seen and widen the radius over time.</li>
                        <li>Share the report link or print the QR poster.</li>
                        <li>Found a pet? Check <a href="<?= e(pets_url(['kind' => 'lost'])) ?>">lost reports</a> first. Similar reports are matched automatically.</li>
                        <li>Add a <a href="areas.php">watch area</a> with “Lost &amp; Found” to get notified about new reports.</li>
                    </ul>
                </div>
            </div>
        </aside>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
