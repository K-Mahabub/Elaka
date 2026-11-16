<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$loc = user_location();
$center = $loc ? [$loc['lat'], $loc['lng']] : [23.8103, 90.4125]; // default view: Dhaka
$pageTitle = 'Your location';
$needsMap = true;
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
    <h1>📍 Your location</h1>
    <p class="muted">Elaka only shows posts made near you. Your exact position is never shown to others.</p>
</section>

<div class="grid-2">
    <div class="card">
        <h2>Detect automatically</h2>
        <p>Use your device's GPS / browser location. This is the most accurate option.</p>
        <button class="btn btn-primary" type="button" data-locate data-return="index.php">📡 Use my current location</button>
        <p class="muted small" data-locate-status></p>

        <hr>
        <h2>Viewing radius</h2>
        <form method="post" action="set_location.php" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="return" value="location.php">
            <div class="chips">
                <?php foreach (config('radius_options') as $r): ?>
                    <label class="chip-radio">
                        <input type="radio" name="radius" value="<?= $r ?>" <?= viewer_radius() === $r ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span><?= e(format_radius($r)) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </form>
        <form method="post" action="set_location.php" class="form row-form">
            <?= csrf_field() ?>
            <input type="hidden" name="return" value="location.php">
            <label>Custom radius (km)
                <input type="number" name="radius_km" required
                       min="<?= config('radius_min') / 1000 ?>" max="<?= config('radius_max') / 1000 ?>" step="any"
                       value="<?= e(rtrim(rtrim(number_format(viewer_radius() / 1000, 2, '.', ''), '0'), '.')) ?>">
            </label>
            <button class="btn" type="submit">Set radius</button>
        </form>
        <p class="muted small">Current: <?= e(format_radius(viewer_radius())) ?>
            · any value from <?= e(format_radius((int)config('radius_min'))) ?> to <?= e(format_radius((int)config('radius_max'))) ?></p>

        <?php if ($loc): ?>
            <hr>
            <p class="small muted">
                Current: <?= e(number_format($loc['lat'], 5)) ?>, <?= e(number_format($loc['lng'], 5)) ?>
                (<?= $loc['source'] === 'gps' ? 'GPS' : 'set manually' ?>, <?= e(time_ago(date('Y-m-d H:i:s', $loc['at']))) ?>)
                <?php if ($loc['stale']): ?><br><strong>This location is old — please refresh it.</strong><?php endif; ?>
            </p>
        <?php endif; ?>
    </div>

    <div class="card">
        <?php if (config('allow_manual_location')): ?>
            <h2>Or pick on the map</h2>
            <p class="muted small">Click the map to place yourself, then save.</p>
            <div class="map" id="locmap"
                 data-map='<?= e(json_encode([
                     'center' => $center, 'zoom' => $loc ? 15 : 12,
                     'circle' => $loc ? ['center' => $center, 'radius' => viewer_radius()] : null,
                     'picker' => ['lat' => '#m_lat', 'lng' => '#m_lng', 'marker' => $loc ? $center : null],
                 ])) ?>'></div>
            <form method="post" action="set_location.php" class="form row-form">
                <?= csrf_field() ?>
                <input type="hidden" name="source" value="manual">
                <input type="hidden" name="return" value="index.php">
                <input type="hidden" name="lat" id="m_lat" value="<?= $loc ? e($loc['lat']) : '' ?>">
                <input type="hidden" name="lng" id="m_lng" value="<?= $loc ? e($loc['lng']) : '' ?>">
                <button class="btn" type="submit">Save map location</button>
            </form>
        <?php else: ?>
            <h2>Why location?</h2>
            <p>Posts on Elaka are tied to a place. You can only read and create posts within your selected radius.</p>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
