<?php
/** Report a lost or found pet. Creates a 'lost_found' post plus its pet_reports / pet_photos rows. */
require __DIR__ . '/includes/bootstrap.php';
$me = require_login();
$loc = user_location();

if (!$loc) {
    flash('info', 'Set your location first. Pet reports are shown to people near where the pet was last seen.');
    redirect('location.php');
}

$maxKm = pet_cfg('max_report_km');
$maxPhotos = pet_cfg('max_photos');
$form = [
    'kind' => isset(pet_kinds()[$_GET['kind'] ?? '']) ? $_GET['kind'] : 'lost',
    'pet_type' => 'dog', 'pet_name' => '', 'breed' => '', 'color' => '', 'sex' => 'unknown', 'body' => '',
    'seen_at' => datetime_local(), 'lat' => (string)$loc['lat'], 'lng' => (string)$loc['lng'], 'location_text' => '',
    'contact' => '', 'contact_visibility' => 'members', 'radius_m' => '2000',
    'is_anonymous' => '', 'link_sharing' => '1', 'precise_location' => '',
];
$errors = [];

if (is_post()) {
    verify_csrf();
    foreach ($form as $k => $_) {
        $form[$k] = input($k);
    }
    $radius = (int)$form['radius_m'];
    $seenTs = strtotime($form['seen_at']) ?: null;

    if (honeypot_tripped()) {
        $errors[] = 'Report rejected.';
    }
    if (!isset(pet_kinds()[$form['kind']])) {
        $errors[] = 'Choose whether the pet is lost or found.';
        $form['kind'] = 'lost';
    }
    if (!isset(pet_types()[$form['pet_type']])) {
        $errors[] = 'Choose the type of animal.';
        $form['pet_type'] = 'other';
    }
    if (!isset(pet_sexes()[$form['sex']])) {
        $form['sex'] = 'unknown';
    }
    if (!isset(pet_contact_visibilities()[$form['contact_visibility']])) {
        $form['contact_visibility'] = 'members';
    }
    if ($form['kind'] === 'lost' && mb_strlen($form['pet_name']) < 1) {
        $errors[] = 'Please give your pet\'s name. It helps people call them.';
    }
    foreach (['pet_name' => 60, 'breed' => 80, 'color' => 80, 'location_text' => 190, 'contact' => 120] as $k => $max) {
        if (mb_strlen($form[$k]) > $max) {
            $errors[] = ucfirst(str_replace('_', ' ', $k)) . " is too long (max $max).";
        }
    }
    if (mb_strlen($form['color']) < 2) {
        $errors[] = 'Describe the colour / markings (e.g. "brown with white paws").';
    }
    if (mb_strlen($form['body']) < 10 || mb_strlen($form['body']) > 5000) {
        $errors[] = 'Add a description of 10–5000 characters: size, collar, behaviour, distinctive marks…';
    }
    if (count_links($form['body']) > config('max_links_per_post')) {
        $errors[] = 'Too many links in the description.';
    }
    if (!$seenTs) {
        $errors[] = 'When was the pet ' . ($form['kind'] === 'lost' ? 'last seen' : 'found') . '?';
    } elseif ($seenTs > time() + 600) {
        $errors[] = 'The date and time cannot be in the future.';
    } elseif ($seenTs < time() - 365 * 86400) {
        $errors[] = 'The date is more than a year ago.';
    }
    if (!valid_coords($form['lat'], $form['lng'])) {
        $errors[] = 'Pick the ' . ($form['kind'] === 'lost' ? 'last-seen' : 'found') . ' location on the map or use GPS.';
    } elseif (haversine($loc['lat'], $loc['lng'], (float)$form['lat'], (float)$form['lng']) > $maxKm * 1000) {
        $errors[] = "The location must be within $maxKm km of you.";
    }
    if (!in_array($radius, config('radius_options'), true)) {
        $errors[] = 'Choose who can see the report.';
    }

    $title = pet_report_title($form['kind'], $form['pet_type'], $form['pet_name'], $form['breed'], $form['color']);

    // Same spam rules as normal posts.
    if (!$errors && !has_role('moderator', $me) && rate_limited('posts', (int)$me['id'], (int)config('rate_limit_posts'))) {
        $errors[] = 'You are posting too quickly. Please wait a few minutes.';
    }
    if (!$errors && q('SELECT 1 FROM posts WHERE user_id = ? AND title = ? AND created_at > NOW() - INTERVAL 1 HOUR', [$me['id'], $title])->fetchColumn()) {
        $errors[] = 'You already reported this pet recently.';
    }

    $photos = [];
    if (!$errors) {
        try {
            $photos = handle_multi_image_upload('photos', $maxPhotos);
        } catch (RuntimeException $ex) {
            $errors[] = $ex->getMessage();
        }
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Pet reports stay up until the owner marks them reunited / closed (no automatic expiry).
            q(
                'INSERT INTO posts (user_id, category, subtype, title, body, image, lat, lng, radius_m, is_anonymous, contact, location_text)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $me['id'], 'lost_found', $form['kind'], $title, $form['body'], $photos[0] ?? null,
                    (float)$form['lat'], (float)$form['lng'], $radius, $form['is_anonymous'] ? 1 : 0,
                    $form['contact'] ?: null, $form['location_text'] ?: null,
                ]
            );
            $postId = (int)$pdo->lastInsertId();
            q(
                'INSERT INTO pet_reports (post_id, pet_name, pet_type, breed, color, sex, seen_at, share_token,
                                          link_sharing, contact_visibility, precise_location)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $postId, $form['pet_name'] ?: null, $form['pet_type'], $form['breed'] ?: null, $form['color'], $form['sex'],
                    date('Y-m-d H:i:s', min($seenTs, time())), bin2hex(random_bytes(16)),
                    $form['link_sharing'] ? 1 : 0, $form['contact_visibility'], $form['precise_location'] ? 1 : 0,
                ]
            );
            foreach ($photos as $p) {
                q('INSERT INTO pet_photos (post_id, filename) VALUES (?, ?)', [$postId, $p]);
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            array_walk($photos, 'delete_image');
            throw $ex;
        }

        $sent = notify_watchers(get_post($postId));
        $matches = notify_pet_matches(get_pet_report($postId));
        flash('success', 'Report published. Visible to people within ' . format_radius($radius) . ' of the '
            . ($form['kind'] === 'lost' ? 'last-seen' : 'found') . ' location'
            . ($sent ? " · $sent watcher(s) notified" : '')
            . ($matches ? ' · ' . count($matches) . ' possible match(es) found below' : '') . '.');
        redirect(pet_url($postId) . ($matches ? '#matches' : ''));
    }
}

$pageTitle = $form['kind'] === 'found' ? 'Report a found pet' : 'Report a lost pet';
$needsMap = true;
require __DIR__ . '/includes/header.php';
?>
<p><a href="pets.php" class="back-link"><?= icon('back') ?> Lost pet board</a></p>
<section class="page-head">
    <h1>🐾 Report a lost or found pet</h1>
    <p class="muted">Neighbours near the spot you pick will see your report, and similar reports are matched automatically.</p>
</section>

<?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach; ?>

<form method="post" enctype="multipart/form-data" class="form card post-form" id="petForm">
    <?= csrf_field() ?>
    <?= honeypot_field() ?>

    <fieldset>
        <legend>What happened?</legend>
        <div class="cat-picker">
            <label class="cat-option">
                <input type="radio" name="kind" value="lost" <?= $form['kind'] === 'lost' ? 'checked' : '' ?>>
                <span><b>🔴 I lost my pet</b><small>Your pet is missing. Tell neighbours where it was last seen.</small></span>
            </label>
            <label class="cat-option">
                <input type="radio" name="kind" value="found" <?= $form['kind'] === 'found' ? 'checked' : '' ?>>
                <span><b>🟢 I found a pet</b><small>You found or are sheltering an animal that is not yours.</small></span>
            </label>
        </div>
    </fieldset>

    <fieldset>
        <legend>Animal</legend>
        <div class="chips">
            <?php foreach (pet_types() as $k => $t): ?>
                <label class="chip-radio"><input type="radio" name="pet_type" value="<?= e($k) ?>" <?= $form['pet_type'] === $k ? 'checked' : '' ?>>
                    <span><?= $t['icon'] ?> <?= e($t['label']) ?></span></label>
            <?php endforeach; ?>
        </div>
    </fieldset>

    <div class="grid-2">
        <label>Pet name <small class="muted" data-pet-kind="found">(if known, e.g. from a tag)</small>
            <input type="text" name="pet_name" maxlength="60" value="<?= e($form['pet_name']) ?>" placeholder="e.g. Bruno">
        </label>
        <label>Breed (optional)
            <input type="text" name="breed" maxlength="80" value="<?= e($form['breed']) ?>" placeholder="e.g. Labrador, Persian, mixed">
        </label>
        <label>Colour &amp; markings
            <input type="text" name="color" maxlength="80" value="<?= e($form['color']) ?>" required placeholder="e.g. brown with white paws">
        </label>
        <label>Sex
            <select name="sex">
                <?php foreach (pet_sexes() as $k => $l): ?><option value="<?= e($k) ?>" <?= $form['sex'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
            </select>
        </label>
    </div>

    <label>Description
        <textarea name="body" rows="5" maxlength="5000" required minlength="10"
                  placeholder="Size, age, collar / tag, microchip, behaviour (shy, friendly), distinctive marks…"><?= e($form['body']) ?></textarea>
    </label>

    <label>Photos (up to <?= $maxPhotos ?>, max <?= round(config('upload_max_bytes') / 1048576) ?> MB each)
        <input type="file" name="photos[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple data-max-files="<?= $maxPhotos ?>">
        <small class="muted">Clear, recent photos make matching and recognition much easier.</small>
    </label>
    <div class="pet-previews" data-previews-for="photos[]"></div>

    <fieldset>
        <legend><span data-pet-kind="lost">Where &amp; when was it last seen?</span><span data-pet-kind="found">Where &amp; when did you find it?</span></legend>
        <div class="grid-2">
            <label>Date &amp; time
                <input type="datetime-local" name="seen_at" value="<?= e($form['seen_at']) ?>" max="<?= e(datetime_local(time() + 600)) ?>" required>
            </label>
            <label>Place / landmark (optional)
                <input type="text" name="location_text" maxlength="190" value="<?= e($form['location_text']) ?>" placeholder="e.g. near the lake park gate">
            </label>
        </div>
        <p class="small muted">Tap the map to mark the exact spot, or
            <button class="linklike" type="button" data-fill-coords="#p_lat,#p_lng">📡 use my GPS position</button>.
            Must be within <?= $maxKm ?> km of you.</p>
        <div class="map" data-map='<?= e(json_encode([
            'center' => [(float)$form['lat'] ?: $loc['lat'], (float)$form['lng'] ?: $loc['lng']],
            'zoom' => 15,
            'circle' => ['center' => [(float)$form['lat'] ?: $loc['lat'], (float)$form['lng'] ?: $loc['lng']], 'radius' => (int)$form['radius_m'], 'color' => '#f7923b', 'bind' => '[data-radius-select]', 'follow' => true],
            'me' => [$loc['lat'], $loc['lng']],
            'picker' => ['lat' => '#p_lat', 'lng' => '#p_lng', 'marker' => valid_coords($form['lat'], $form['lng']) ? [(float)$form['lat'], (float)$form['lng']] : null],
        ])) ?>'></div>
        <input type="hidden" name="lat" id="p_lat" value="<?= e($form['lat']) ?>">
        <input type="hidden" name="lng" id="p_lng" value="<?= e($form['lng']) ?>">
    </fieldset>

    <div class="grid-2">
        <label>Who can see it?
            <select name="radius_m" data-radius-select>
                <?php foreach (config('radius_options') as $r): ?>
                    <option value="<?= $r ?>" <?= (int)$form['radius_m'] === $r ? 'selected' : '' ?>>People within <?= e(format_radius($r)) ?> of the spot</option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Contact (optional)
            <input type="text" name="contact" maxlength="120" value="<?= e($form['contact']) ?>" placeholder="Phone or email">
        </label>
    </div>

    <fieldset class="pet-privacy">
        <legend>Privacy</legend>
        <label>Show my contact details to
            <select name="contact_visibility">
                <?php foreach (pet_contact_visibilities() as $k => $l): ?>
                    <option value="<?= e($k) ?>" <?= $form['contact_visibility'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="check"><input type="checkbox" name="link_sharing" value="1" <?= $form['link_sharing'] ? 'checked' : '' ?>>
            <span>Allow a share link &amp; QR code <small class="muted">— anyone with the link can view the report and send sightings, even from outside the radius. You can turn it off later.</small></span></label>
        <label class="check"><input type="checkbox" name="precise_location" value="1" <?= $form['precise_location'] ? 'checked' : '' ?>>
            <span>Show the precise spot on the map <small class="muted">— otherwise it is rounded to about 100 m like other posts.</small></span></label>
        <label class="check"><input type="checkbox" name="is_anonymous" value="1" <?= $form['is_anonymous'] ? 'checked' : '' ?>>
            <span>Hide my name <small class="muted">— moderators can still see it to prevent abuse.</small></span></label>
    </fieldset>

    <div class="form-actions">
        <a class="btn" href="pets.php">Cancel</a>
        <button class="btn btn-primary" type="submit">Publish report</button>
    </div>
</form>
<?php
$extraScripts = ['assets/js/pets.js?v=1'];
require __DIR__ . '/includes/footer.php';
