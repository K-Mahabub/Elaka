<?php
/**
 * Create or edit a marketplace listing.
 * Creates a 'marketplace' post plus its market_listings / market_photos rows.
 */
require __DIR__ . '/includes/bootstrap.php';
$me = require_login();
$loc = user_location();

$maxKm = market_cfg('max_km');
$maxPhotos = market_cfg('max_photos');

// Edit mode: only the seller may edit.
$editing = null;
if (isset($_GET['edit'])) {
    $editing = get_listing((int)$_GET['edit']);
    if (!$editing || (int)$editing['user_id'] !== (int)$me['id'] || $editing['status'] === 'removed') {
        flash('error', 'You can only edit your own listings.');
        redirect('marketplace.php?mine=1');
    }
}
if (!$editing && !$loc) {
    flash('info', 'Set your location first. Listings are shown to people near the pick-up point.');
    redirect('location.php');
}

$existingPhotos = $editing ? market_photos($editing) : [];
$form = $editing ? [
    'subtype' => $editing['subtype'] ?: 'sell', 'title' => $editing['title'], 'body' => (string)$editing['body'],
    'item_category' => $editing['item_category'], 'item_condition' => (string)$editing['item_condition'],
    'price' => $editing['price'] !== null ? rtrim(rtrim((string)$editing['price'], '0'), '.') : '',
    'negotiable' => $editing['negotiable'] ? '1' : '', 'location_text' => (string)$editing['location_text'],
    'lat' => (string)$editing['lat'], 'lng' => (string)$editing['lng'], 'radius_m' => (string)$editing['radius_m'],
    'phone' => (string)$editing['phone'], 'whatsapp' => $editing['whatsapp'] ? '1' : '', 'email' => (string)$editing['email'],
] : [
    'subtype' => 'sell', 'title' => '', 'body' => '',
    'item_category' => isset(market_categories()[$_GET['cat'] ?? '']) ? $_GET['cat'] : 'other', 'item_condition' => 'good',
    'price' => '', 'negotiable' => '', 'location_text' => '',
    'lat' => (string)$loc['lat'], 'lng' => (string)$loc['lng'], 'radius_m' => '2000',
    'phone' => '', 'whatsapp' => '', 'email' => '',
];
$errors = [];

if (is_post()) {
    verify_csrf();
    foreach ($form as $k => $_) {
        $form[$k] = input($k);
    }
    $radius = (int)$form['radius_m'];
    $removeIds = array_map('intval', (array)($_POST['remove_photo'] ?? []));
    $keptPhotos = array_values(array_filter($existingPhotos, fn($p) => !in_array((int)$p['id'], $removeIds, true)));

    if (honeypot_tripped()) {
        $errors[] = 'Listing rejected.';
    }
    if (!isset(market_types()[$form['subtype']])) {
        $errors[] = 'Choose what kind of listing this is.';
        $form['subtype'] = 'sell';
    }
    if (!isset(market_categories()[$form['item_category']])) {
        $errors[] = 'Choose a category.';
        $form['item_category'] = 'other';
    }
    if ($form['item_condition'] !== '' && !isset(market_conditions()[$form['item_condition']])) {
        $form['item_condition'] = '';
    }
    if (mb_strlen($form['title']) < 3 || mb_strlen($form['title']) > 150) {
        $errors[] = 'Give the item a title of 3–150 characters.';
    }
    if (mb_strlen($form['body']) > 5000) {
        $errors[] = 'The description is too long (max 5000 characters).';
    }
    if (count_links($form['title'] . ' ' . $form['body']) > config('max_links_per_post')) {
        $errors[] = 'Too many links in the description.';
    }

    $price = null;
    if ($form['subtype'] === 'free') {
        $price = 0.0;
    } elseif ($form['price'] !== '') {
        if (!is_numeric($form['price']) || (float)$form['price'] < 0 || (float)$form['price'] > 1e9) {
            $errors[] = 'Enter a valid price (0 or more), or leave it blank.';
        } else {
            $price = round((float)$form['price'], 2);
        }
    } elseif ($form['subtype'] === 'sell') {
        $errors[] = 'Enter a price for items for sale (use “Free / giveaway” for free items).';
    }

    foreach (['location_text' => 190, 'phone' => 30, 'email' => 190] as $k => $max) {
        if (mb_strlen($form[$k]) > $max) {
            $errors[] = ucfirst(str_replace('_', ' ', $k)) . " is too long (max $max).";
        }
    }
    if ($form['phone'] !== '' && !market_valid_phone($form['phone'])) {
        $errors[] = 'Enter a valid phone number (digits, spaces or dashes, e.g. 01712-345678).';
    }
    if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }
    if ($form['phone'] === '') {
        $form['whatsapp'] = '';
    }

    if (!valid_coords($form['lat'], $form['lng'])) {
        $errors[] = 'Pick the pick-up area on the map or use GPS.';
    } elseif ($loc && haversine($loc['lat'], $loc['lng'], (float)$form['lat'], (float)$form['lng']) > $maxKm * 1000) {
        $errors[] = "The pick-up point must be within $maxKm km of you.";
    } elseif (!$loc && $editing && ((float)$form['lat'] !== (float)$editing['lat'] || (float)$form['lng'] !== (float)$editing['lng'])) {
        $errors[] = 'Set your location before moving the pick-up point.';
    }
    if (!in_array($radius, config('radius_options'), true)) {
        $errors[] = 'Choose who can see the listing.';
    }

    if (!$editing && !$errors && !has_role('moderator', $me) && rate_limited('posts', (int)$me['id'], (int)config('rate_limit_posts'))) {
        $errors[] = 'You are posting too quickly. Please wait a few minutes.';
    }
    if (!$editing && !$errors && q("SELECT 1 FROM posts WHERE user_id = ? AND category = 'marketplace' AND title = ? AND created_at > NOW() - INTERVAL 1 HOUR", [$me['id'], $form['title']])->fetchColumn()) {
        $errors[] = 'You already listed this item recently.';
    }

    $newPhotos = [];
    if (!$errors) {
        try {
            $newPhotos = handle_multi_image_upload('photos', max(0, $maxPhotos - count($keptPhotos)));
        } catch (RuntimeException $ex) {
            $errors[] = str_contains($ex->getMessage(), 'at most')
                ? "A listing can have at most $maxPhotos photos in total."
                : $ex->getMessage();
        }
    }

    if (!$errors) {
        $values = [
            'subtype' => $form['subtype'], 'title' => $form['title'], 'body' => $form['body'] ?: null, 'price' => $price,
            'lat' => (float)$form['lat'], 'lng' => (float)$form['lng'], 'radius_m' => $radius,
            'location_text' => $form['location_text'] ?: null,
        ];
        $listing = [
            'item_category' => $form['item_category'], 'item_condition' => $form['item_condition'] ?: null,
            'negotiable' => $form['negotiable'] && $price ? 1 : 0, 'phone' => $form['phone'] ?: null,
            'whatsapp' => $form['whatsapp'] ? 1 : 0, 'email' => $form['email'] ?: null,
        ];

        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($editing) {
                $postId = (int)$editing['id'];
                q(
                    'UPDATE posts SET subtype = ?, title = ?, body = ?, price = ?, lat = ?, lng = ?, radius_m = ?, location_text = ? WHERE id = ?',
                    [...array_values($values), $postId]
                );
                market_ensure_row($editing);
                q(
                    'UPDATE market_listings SET item_category = ?, item_condition = ?, negotiable = ?, phone = ?, whatsapp = ?, email = ? WHERE post_id = ?',
                    [...array_values($listing), $postId]
                );
                // Older posts kept their single photo in posts.image: move it into market_photos first.
                if ($existingPhotos && (int)$existingPhotos[0]['id'] === 0 && !in_array(0, $removeIds, true)) {
                    q('INSERT INTO market_photos (post_id, filename) VALUES (?, ?)', [$postId, $existingPhotos[0]['filename']]);
                }
            } else {
                q(
                    'INSERT INTO posts (user_id, category, subtype, title, body, price, lat, lng, radius_m, location_text, is_anonymous, expires_at)
                     VALUES (?, \'marketplace\', ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW() + INTERVAL ? DAY)',
                    [$me['id'], ...array_values($values), market_cfg('expiry_days')]
                );
                $postId = (int)$pdo->lastInsertId();
                q(
                    'INSERT INTO market_listings (post_id, item_category, item_condition, negotiable, phone, whatsapp, email) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$postId, ...array_values($listing)]
                );
            }
            foreach ($newPhotos as $p) {
                q('INSERT INTO market_photos (post_id, filename) VALUES (?, ?)', [$postId, $p]);
            }
            $removed = [];
            foreach ($existingPhotos as $p) {
                if (in_array((int)$p['id'], $removeIds, true)) {
                    q('DELETE FROM market_photos WHERE post_id = ? AND filename = ?', [$postId, $p['filename']]);
                    $removed[] = $p['filename'];
                }
            }
            market_sync_cover($postId);
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            array_walk($newPhotos, 'delete_image');
            throw $ex;
        }
        array_walk($removed, 'delete_image'); // only after the rows are gone for good

        if ($editing) {
            flash('success', 'Listing updated.');
        } else {
            $sent = notify_watchers(get_post($postId));
            flash('success', 'Your item is listed! Visible to people within ' . format_radius($radius) . ' of the pick-up point'
                . ($sent ? " · $sent watcher(s) notified" : '') . '.');
        }
        redirect(market_url($postId));
    }
}

$center = valid_coords($form['lat'], $form['lng'])
    ? [(float)$form['lat'], (float)$form['lng']]
    : ($loc ? [$loc['lat'], $loc['lng']] : [(float)$editing['lat'], (float)$editing['lng']]);
$pageTitle = $editing ? 'Edit listing' : 'Sell something';
$needsMap = true;
require __DIR__ . '/includes/header.php';
?>
<p><a href="<?= $editing ? e(market_url((int)$editing['id'])) : 'marketplace.php' ?>" class="back-link"><?= icon('back') ?> <?= $editing ? 'Back to listing' : 'Marketplace' ?></a></p>
<section class="page-head">
    <h1>🛒 <?= $editing ? 'Edit your listing' : 'List an item' ?></h1>
    <p class="muted">Neighbours near the pick-up point will see it. Listings stay up for <?= market_cfg('expiry_days') ?> days and can be renewed.</p>
</section>

<?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach; ?>

<form method="post" enctype="multipart/form-data" class="form card post-form" id="marketForm">
    <?= csrf_field() ?>
    <?= honeypot_field() ?>

    <fieldset>
        <legend>What are you listing?</legend>
        <div class="chips">
            <?php foreach (market_types() as $k => $l): ?>
                <label class="chip-radio"><input type="radio" name="subtype" value="<?= e($k) ?>" <?= $form['subtype'] === $k ? 'checked' : '' ?>>
                    <span><?= e($l) ?></span></label>
            <?php endforeach; ?>
        </div>
    </fieldset>

    <label>Title
        <input type="text" name="title" maxlength="150" required minlength="3" value="<?= e($form['title']) ?>" placeholder="e.g. Wooden study table with 2 drawers">
    </label>

    <div class="grid-2">
        <label>Category
            <select name="item_category">
                <?php foreach (market_categories() as $k => $c): ?>
                    <option value="<?= e($k) ?>" <?= $form['item_category'] === $k ? 'selected' : '' ?>><?= $c['icon'] ?> <?= e($c['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Condition
            <select name="item_condition">
                <option value="">Not specified</option>
                <?php foreach (market_conditions() as $k => $l): ?>
                    <option value="<?= e($k) ?>" <?= $form['item_condition'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label data-mk-hide-for="free"><span data-mk-price-label>Price (৳)</span>
            <input type="number" name="price" min="0" max="1000000000" step="0.01" value="<?= e($form['price']) ?>" placeholder="e.g. 2500">
        </label>
        <label class="check mk-negotiable" data-mk-hide-for="free"><input type="checkbox" name="negotiable" value="1" <?= $form['negotiable'] ? 'checked' : '' ?>>
            <span>Price is negotiable</span></label>
    </div>

    <label>Description (optional)
        <textarea name="body" rows="5" maxlength="5000" placeholder="Size, brand, age, any defects, what is included, why you are selling…"><?= e($form['body']) ?></textarea>
    </label>

    <?php if ($existingPhotos): ?>
        <fieldset>
            <legend>Current photos <small class="muted">— tick to remove</small></legend>
            <div class="mk-edit-photos">
                <?php foreach ($existingPhotos as $p): ?>
                    <label class="mk-edit-photo">
                        <img src="<?= e(image_url($p['filename'])) ?>" alt="">
                        <span class="check"><input type="checkbox" name="remove_photo[]" value="<?= (int)$p['id'] ?>"> Remove</span>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
    <?php endif; ?>

    <?php $slots = $maxPhotos - count($existingPhotos); ?>
    <label><?= $existingPhotos ? 'Add photos' : 'Photos' ?> (<?= $existingPhotos ? "up to $maxPhotos in total" : "up to $maxPhotos" ?>, max <?= round(config('upload_max_bytes') / 1048576) ?> MB each)
        <input type="file" name="photos[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple data-max-files="<?= max(1, $slots) ?>">
        <small class="muted">The first photo is used as the cover. Clear, well-lit photos sell faster.</small>
    </label>
    <div class="pet-previews" data-previews-for="photos[]"></div>

    <fieldset>
        <legend>Pick-up area</legend>
        <label>Area / landmark (optional)
            <input type="text" name="location_text" maxlength="190" value="<?= e($form['location_text']) ?>" placeholder="e.g. Road 4, near the mosque">
        </label>
        <p class="small muted">Tap the map to set the pick-up point, or
            <button class="linklike" type="button" data-fill-coords="#m_lat,#m_lng">📡 use my GPS position</button>.
            Must be within <?= $maxKm ?> km of you. Buyers only see an approximate location (about 100 m).</p>
        <div class="map" data-map='<?= e(json_encode([
            'center' => $center,
            'zoom' => 15,
            'circle' => ['center' => $center, 'radius' => (int)$form['radius_m'], 'color' => '#1877f2', 'bind' => '[data-radius-select]', 'follow' => true],
            'me' => $loc ? [$loc['lat'], $loc['lng']] : null,
            'picker' => ['lat' => '#m_lat', 'lng' => '#m_lng', 'marker' => $center],
        ])) ?>'></div>
        <input type="hidden" name="lat" id="m_lat" value="<?= e($form['lat']) ?>">
        <input type="hidden" name="lng" id="m_lng" value="<?= e($form['lng']) ?>">
        <label>Who can see it?
            <select name="radius_m" data-radius-select>
                <?php foreach (config('radius_options') as $r): ?>
                    <option value="<?= $r ?>" <?= (int)$form['radius_m'] === $r ? 'selected' : '' ?>>People within <?= e(format_radius($r)) ?> of the pick-up point</option>
                <?php endforeach; ?>
            </select>
        </label>
    </fieldset>

    <fieldset class="mk-contact-fields">
        <legend>How can buyers reach you?</legend>
        <p class="small muted">Buyers can always ask questions in the listing's comments. Add a phone number or email for direct contact. Only signed-in neighbours who can see the listing see these.</p>
        <div class="grid-2">
            <label>Phone (optional)
                <input type="tel" name="phone" maxlength="30" value="<?= e($form['phone']) ?>" placeholder="e.g. 01712-345678">
            </label>
            <label>Email (optional)
                <input type="email" name="email" maxlength="190" value="<?= e($form['email']) ?>" placeholder="you@example.com">
            </label>
        </div>
        <label class="check"><input type="checkbox" name="whatsapp" value="1" <?= $form['whatsapp'] ? 'checked' : '' ?>>
            <span>This number is on WhatsApp <small class="muted">— shows a “WhatsApp” button</small></span></label>
    </fieldset>

    <div class="form-actions">
        <a class="btn" href="<?= $editing ? e(market_url((int)$editing['id'])) : 'marketplace.php' ?>">Cancel</a>
        <button class="btn btn-primary" type="submit"><?= $editing ? 'Save changes' : 'Publish listing' ?></button>
    </div>
</form>
<script>
    // Hide the price for giveaways and adjust its label for "Wanted" listings.
    (function () {
        var f = document.getElementById('marketForm');
        var label = f.querySelector('[data-mk-price-label]');
        function update() {
            var t = (f.querySelector('input[name="subtype"]:checked') || {}).value || 'sell';
            f.querySelectorAll('[data-mk-hide-for]').forEach(function (n) { n.hidden = n.dataset.mkHideFor.split(' ').indexOf(t) !== -1; });
            label.textContent = t === 'buy' ? 'Budget (৳, optional)' : (t === 'sell' ? 'Price (৳)' : 'Price (৳, optional)');
        }
        f.querySelectorAll('input[name="subtype"]').forEach(function (r) { r.addEventListener('change', update); });
        update();
    })();
</script>
<?php
$extraScripts = ['assets/js/pets.js?v=1']; // photo previews + multi-file limit (shared with the pet board)
require __DIR__ . '/includes/footer.php';
