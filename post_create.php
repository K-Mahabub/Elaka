<?php
require __DIR__ . '/includes/bootstrap.php';
$me = require_login();
$loc = user_location();

if (!$loc) {
    flash('info', 'Set your location first — posts are pinned to where you are.');
    redirect('location.php');
}

$cats = categories();
$f = [
    'category' => isset($_GET['cat']) && isset($cats[$_GET['cat']]) ? $_GET['cat'] : 'discussion',
    'subtype' => '', 'title' => '', 'body' => '', 'radius_m' => (string)config('default_radius'),
    'expiry' => 'auto', 'is_anonymous' => '', 'price' => '', 'contact' => '', 'location_text' => '',
    'business_hours' => '', 'event_start' => '', 'event_end' => '',
];
$pollOptions = ['', ''];
$errors = [];

if (is_post()) {
    verify_csrf();
    foreach ($f as $k => $_) {
        $f[$k] = input($k);
    }
    // One subtype <select> per category: take the one matching the chosen category.
    $sub = $_POST['subtype'][$f['category']] ?? '';
    $f['subtype'] = is_string($sub) ? $sub : '';
    $pollOptions = array_values(array_filter(
        array_map(fn($o) => trim((string)$o), (array)($_POST['poll_options'] ?? [])),
        fn($o) => $o !== ''
    ));

    $cat = $cats[$f['category']] ?? null;
    $radius = (int)$f['radius_m'];

    if (honeypot_tripped()) {
        $errors[] = 'Post rejected.';
    }
    if (!$cat) {
        $errors[] = 'Choose a category.';
        $cat = $cats['discussion'];
    }
    if ($cat['subtypes'] && !isset($cat['subtypes'][$f['subtype']])) {
        $errors[] = 'Choose a type for this ' . strtolower($cat['label']) . '.';
    }
    if (!$cat['subtypes']) {
        $f['subtype'] = '';
    }
    if (mb_strlen($f['title']) < 3 || mb_strlen($f['title']) > 150) {
        $errors[] = 'Title must be 3–150 characters.';
    }
    if (mb_strlen($f['body']) > 5000) {
        $errors[] = 'Description is too long (max 5000 characters).';
    }
    if ($f['category'] !== 'poll' && mb_strlen($f['body']) < 1) {
        $errors[] = 'Please add a description.';
    }
    if (!in_array($radius, config('radius_options'), true)) {
        $errors[] = 'Choose a valid visibility radius.';
    }
    if (count_links($f['title'] . ' ' . $f['body']) > config('max_links_per_post')) {
        $errors[] = 'Too many links in this post.';
    }
    if (!isset(expiry_options()[$f['expiry']])) {
        $f['expiry'] = 'auto';
    }

    // Category-specific fields.
    $price = null;
    $eventStart = $eventEnd = null;
    if ($f['category'] === 'marketplace' && $f['price'] !== '') {
        if (!is_numeric($f['price']) || (float)$f['price'] < 0 || (float)$f['price'] > 1e9) {
            $errors[] = 'Price must be a positive number.';
        } else {
            $price = round((float)$f['price'], 2);
        }
    }
    if ($f['category'] === 'event') {
        $eventStart = strtotime($f['event_start']) ?: null;
        $eventEnd = $f['event_end'] !== '' ? (strtotime($f['event_end']) ?: null) : null;
        if (!$eventStart) {
            $errors[] = 'Event start time is required.';
        } elseif ($eventStart < time() - 86400) {
            $errors[] = 'Event start time is in the past.';
        }
        if ($eventStart && $eventEnd && $eventEnd <= $eventStart) {
            $errors[] = 'Event end must be after the start.';
        }
    }
    if ($f['category'] === 'poll') {
        $pollOptions = array_slice(array_unique($pollOptions), 0, 6);
        if (count($pollOptions) < 2) {
            $errors[] = 'A poll needs at least two different options.';
        }
        foreach ($pollOptions as $o) {
            if (mb_strlen($o) > 120) {
                $errors[] = 'Poll options must be at most 120 characters.';
                break;
            }
        }
    }
    foreach (['contact' => 120, 'location_text' => 190, 'business_hours' => 120] as $k => $max) {
        if (mb_strlen($f[$k]) > $max) {
            $errors[] = ucfirst(str_replace('_', ' ', $k)) . " is too long (max $max).";
        }
    }

    // Spam checks.
    if (!$errors && !has_role('moderator', $me) && rate_limited('posts', (int)$me['id'], (int)config('rate_limit_posts'))) {
        $errors[] = 'You are posting too quickly. Please wait a few minutes.';
    }
    if (!$errors && q('SELECT 1 FROM posts WHERE user_id = ? AND title = ? AND created_at > NOW() - INTERVAL 1 HOUR', [$me['id'], $f['title']])->fetchColumn()) {
        $errors[] = 'You already posted this recently.';
    }

    // Expiry.
    $expiresAt = null;
    if (!$errors) {
        if ($f['expiry'] === 'auto') {
            if ($f['category'] === 'event') {
                $expiresAt = $eventEnd ?: $eventStart + 6 * 3600;
            } elseif ($cat['expiry']) {
                $expiresAt = time() + $cat['expiry'] * 3600;
            }
        } elseif ($f['expiry'] !== 'never') {
            $expiresAt = time() + (int)$f['expiry'] * 3600;
        }
        // Alerts are temporary by nature: never longer than a week.
        if ($f['category'] === 'alert' && ($expiresAt === null || $expiresAt > time() + 168 * 3600)) {
            $expiresAt = time() + 168 * 3600;
        }
    }

    $image = null;
    if (!$errors) {
        try {
            $image = handle_image_upload('image');
        } catch (RuntimeException $ex) {
            $errors[] = $ex->getMessage();
        }
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            q(
                'INSERT INTO posts (user_id, category, subtype, title, body, image, lat, lng, radius_m, is_anonymous,
                                    price, contact, location_text, business_hours, event_start, event_end, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $me['id'], $f['category'], $f['subtype'] ?: null, $f['title'], $f['body'] ?: null, $image,
                    $loc['lat'], $loc['lng'], $radius, $f['is_anonymous'] ? 1 : 0,
                    $price, $f['contact'] ?: null, $f['location_text'] ?: null, $f['business_hours'] ?: null,
                    $eventStart ? date('Y-m-d H:i:s', $eventStart) : null,
                    $eventEnd ? date('Y-m-d H:i:s', $eventEnd) : null,
                    $expiresAt ? date('Y-m-d H:i:s', $expiresAt) : null,
                ]
            );
            $postId = (int)$pdo->lastInsertId();
            foreach ($f['category'] === 'poll' ? $pollOptions : [] as $o) {
                q('INSERT INTO poll_options (post_id, label) VALUES (?, ?)', [$postId, $o]);
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            delete_image($image);
            throw $ex;
        }

        $sent = notify_watchers(get_post($postId));
        flash('success', 'Posted! Visible to people within ' . format_radius($radius) . ($sent ? " · $sent watcher(s) notified." : '.'));
        redirect('post.php?id=' . $postId);
    }
}

$pageTitle = 'New post';
$needsMap = true;
require __DIR__ . '/includes/header.php';
?>
<section class="page-head">
    <h1>Create a post</h1>
    <p class="muted">Your post is pinned to your current location and only visible to people nearby.</p>
</section>

<?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach; ?>

<form method="post" enctype="multipart/form-data" class="form card post-form" id="postForm">
    <?= csrf_field() ?>
    <?= honeypot_field() ?>

    <fieldset>
        <legend>What are you posting?</legend>
        <div class="cat-picker">
            <?php foreach ($cats as $key => $c): ?>
                <label class="cat-option">
                    <input type="radio" name="category" value="<?= e($key) ?>" <?= $f['category'] === $key ? 'checked' : '' ?>>
                    <span><b><?= $c['icon'] ?> <?= e($c['label']) ?></b><small><?= e($c['hint']) ?></small></span>
                </label>
            <?php endforeach; ?>
        </div>
    </fieldset>

    <?php foreach ($cats as $key => $c): if (!$c['subtypes']) continue; ?>
        <label data-show-for="<?= e($key) ?>">Type
            <select name="subtype[<?= e($key) ?>]">
                <option value="">— choose —</option>
                <?php foreach ($c['subtypes'] as $sk => $sl): ?>
                    <option value="<?= e($sk) ?>" <?= $f['category'] === $key && $f['subtype'] === $sk ? 'selected' : '' ?>><?= e($sl) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    <?php endforeach; ?>

    <label>Title <input type="text" name="title" value="<?= e($f['title']) ?>" maxlength="150" required
                        placeholder="e.g. Road closed near the market"></label>

    <label><span data-hide-for="poll">Description</span><span data-show-for="poll">Question details (optional)</span>
        <textarea name="body" rows="5" maxlength="5000" placeholder="Share the details…"><?= e($f['body']) ?></textarea>
    </label>

    <!-- Event -->
    <div class="grid-2" data-show-for="event">
        <label>Starts <input type="datetime-local" name="event_start" value="<?= e($f['event_start']) ?>"></label>
        <label>Ends (optional) <input type="datetime-local" name="event_end" value="<?= e($f['event_end']) ?>"></label>
    </div>

    <!-- Marketplace -->
    <p class="flash flash-info" data-show-for="marketplace">Tip: the <a href="market_sell.php">Marketplace listing form</a> adds multiple photos, a category, condition, and call / WhatsApp buttons.</p>
    <label data-show-for="marketplace">Price (৳, optional) <input type="number" name="price" min="0" step="0.01" value="<?= e($f['price']) ?>"></label>

    <!-- Location text for events / services / lost & found -->
    <label data-show-for="event service lost_found">
        <span data-show-for="event">Venue</span><span data-show-for="service">Address</span><span data-show-for="lost_found">Where (approx.)</span>
        <input type="text" name="location_text" maxlength="190" value="<?= e($f['location_text']) ?>">
    </label>
    <label data-show-for="service">Opening hours <input type="text" name="business_hours" maxlength="120" value="<?= e($f['business_hours']) ?>" placeholder="e.g. Sat–Thu 9am–9pm"></label>
    <label data-show-for="marketplace service lost_found event">Contact (optional)
        <input type="text" name="contact" maxlength="120" value="<?= e($f['contact']) ?>" placeholder="Phone, email, or 'message in comments'">
    </label>

    <!-- Poll -->
    <fieldset data-show-for="poll">
        <legend>Poll options (2–6)</legend>
        <div id="pollOptions">
            <?php foreach (array_pad($pollOptions, 2, '') as $i => $o): ?>
                <input type="text" name="poll_options[]" maxlength="120" value="<?= e($o) ?>" placeholder="Option <?= $i + 1 ?>">
            <?php endforeach; ?>
        </div>
        <button class="btn btn-sm" type="button" data-add-option>+ Add option</button>
    </fieldset>

    <label>Photo (optional, max <?= round(config('upload_max_bytes') / 1048576) ?> MB)
        <input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
    </label>

    <div class="grid-2">
        <label>Who can see it?
            <select name="radius_m" data-radius-select>
                <?php foreach (config('radius_options') as $r): ?>
                    <option value="<?= $r ?>" <?= (int)$f['radius_m'] === $r ? 'selected' : '' ?>>People within <?= e(format_radius($r)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Remove automatically after
            <select name="expiry">
                <?php foreach (expiry_options() as $k => $l): ?>
                    <option value="<?= e($k) ?>" <?= $f['expiry'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
            </select>
            <small class="muted">Defaults: alerts 1 day, events end time, lost &amp; found / marketplace 30 days.</small>
        </label>
    </div>

    <div class="map map-sm" data-map='<?= e(json_encode([
        'center' => [$loc['lat'], $loc['lng']],
        'circle' => ['center' => [$loc['lat'], $loc['lng']], 'radius' => (int)$f['radius_m'], 'bind' => '[data-radius-select]'],
        'me' => [$loc['lat'], $loc['lng']],
    ])) ?>'></div>

    <label class="check">
        <input type="checkbox" name="is_anonymous" value="1" <?= $f['is_anonymous'] ? 'checked' : '' ?>>
        <span>Post anonymously <small class="muted">— your name is hidden from other users (moderators can still see it to prevent abuse).</small></span>
    </label>

    <div class="form-actions">
        <a class="btn" href="index.php">Cancel</a>
        <button class="btn btn-primary" type="submit">Publish</button>
    </div>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
