<?php
require __DIR__ . '/includes/bootstrap.php';
$me = require_login();
$loc = user_location();

$watchable = ['alert', 'event', 'lost_found', 'marketplace', 'service', 'news', 'discussion', 'poll'];
$errors = [];

if (is_post()) {
    verify_csrf();

    if (input('action') === 'delete') {
        q('DELETE FROM watch_areas WHERE id = ? AND user_id = ?', [(int)input('id'), $me['id']]);
        flash('success', 'Watch area removed.');
        redirect('areas.php');
    }

    $name = input('name');
    $lat = $_POST['lat'] ?? '';
    $lng = $_POST['lng'] ?? '';
    $radius = (int)input('radius_m');
    $cats = array_values(array_intersect($watchable, (array)($_POST['categories'] ?? [])));

    if (mb_strlen($name) < 1 || mb_strlen($name) > 60) {
        $errors[] = 'Give the area a short name (e.g. "Home", "Office").';
    }
    if (!valid_coords($lat, $lng)) {
        $errors[] = 'Pick the area on the map.';
    } elseif (!$loc) {
        $errors[] = 'Set your own location first.';
    } elseif (haversine($loc['lat'], $loc['lng'], (float)$lat, (float)$lng) > config('watch_area_max_km') * 1000) {
        $errors[] = 'Watch areas must be within ' . config('watch_area_max_km') . ' km of your current location.';
    }
    if (!in_array($radius, config('radius_options'), true)) {
        $errors[] = 'Choose a radius.';
    }
    if (!$cats) {
        $errors[] = 'Choose at least one kind of post to be notified about.';
    }
    $count = (int)q('SELECT COUNT(*) FROM watch_areas WHERE user_id = ?', [$me['id']])->fetchColumn();
    if ($count >= config('max_watch_areas')) {
        $errors[] = 'You can have at most ' . config('max_watch_areas') . ' watch areas.';
    }

    if (!$errors) {
        q(
            'INSERT INTO watch_areas (user_id, name, lat, lng, radius_m, categories) VALUES (?, ?, ?, ?, ?, ?)',
            [$me['id'], $name, (float)$lat, (float)$lng, $radius, implode(',', $cats)]
        );
        flash('success', 'You will be notified about new posts in “' . $name . '”.');
        redirect('areas.php');
    }
}

$areas = q('SELECT * FROM watch_areas WHERE user_id = ? ORDER BY created_at', [$me['id']])->fetchAll();

$pageTitle = 'Watch areas';
$needsMap = true;
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
    <h1>🔔 Location-based notifications</h1>
    <p class="muted">Choose places you care about — home, work, your child's school — and get notified when something is posted there.</p>
</section>

<?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach; ?>

<div class="grid-2">
    <div>
        <?php if (!$areas): ?>
            <div class="card empty"><p>No watch areas yet.</p></div>
        <?php endif; ?>
        <?php foreach ($areas as $a): ?>
            <div class="card area-card">
                <div>
                    <h3><?= e($a['name']) ?></h3>
                    <p class="muted small">Within <?= e(format_radius((int)$a['radius_m'])) ?>
                        <?php if ($loc): ?> · <?= e(format_distance(haversine($loc['lat'], $loc['lng'], (float)$a['lat'], (float)$a['lng']))) ?> from you<?php endif; ?></p>
                    <p class="small">
                        <?php foreach (explode(',', $a['categories']) as $c): if (!category($c)) continue; ?>
                            <span class="cat-tag cat-<?= e($c) ?>"><?= category($c)['icon'] ?> <?= e(category($c)['label']) ?></span>
                        <?php endforeach; ?>
                    </p>
                </div>
                <form method="post" data-confirm="Remove this watch area?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <button class="btn btn-sm btn-danger" type="submit">Remove</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <h2>Add a watch area</h2>
        <?php if (!$loc): ?>
            <p>Set your location first so we can show the map around you.</p>
            <a class="btn btn-primary" href="location.php">Set location</a>
        <?php elseif (count($areas) >= config('max_watch_areas')): ?>
            <p class="muted">You have reached the maximum of <?= (int)config('max_watch_areas') ?> areas.</p>
        <?php else: ?>
            <form method="post" class="form">
                <?= csrf_field() ?>
                <label>Name <input type="text" name="name" maxlength="60" required placeholder="e.g. Home" value="<?= e(input('name')) ?>"></label>
                <p class="small muted">Click the map to choose the centre (defaults to where you are).</p>
                <div class="map map-sm" data-map='<?= e(json_encode([
                    'center' => [$loc['lat'], $loc['lng']],
                    'me' => [$loc['lat'], $loc['lng']],
                    'circle' => ['center' => [$loc['lat'], $loc['lng']], 'radius' => (int)config('default_radius'), 'bind' => '[name=radius_m]', 'follow' => true],
                    'picker' => ['lat' => '#a_lat', 'lng' => '#a_lng', 'marker' => [$loc['lat'], $loc['lng']]],
                ])) ?>'></div>
                <input type="hidden" name="lat" id="a_lat" value="<?= e($loc['lat']) ?>">
                <input type="hidden" name="lng" id="a_lng" value="<?= e($loc['lng']) ?>">
                <label>Radius
                    <select name="radius_m">
                        <?php foreach (config('radius_options') as $r): ?>
                            <option value="<?= $r ?>" <?= $r === (int)config('default_radius') ? 'selected' : '' ?>><?= e(format_radius($r)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <fieldset>
                    <legend>Notify me about</legend>
                    <div class="chips">
                        <?php foreach ($watchable as $c): ?>
                            <label class="chip-check">
                                <input type="checkbox" name="categories[]" value="<?= e($c) ?>" <?= in_array($c, ['alert', 'event', 'lost_found'], true) ? 'checked' : '' ?>>
                                <span><?= category($c)['icon'] ?> <?= e(category($c)['label']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <button class="btn btn-primary" type="submit">Save watch area</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
