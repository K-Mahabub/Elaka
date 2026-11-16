<?php
/** A single lost / found pet report: photos, map, sightings, comments, matches, sharing and owner tools. */
require __DIR__ . '/includes/bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
$pet = get_pet_report($id);
if (!$pet) {
    if (current_user() && get_post($id)) {
        redirect('post.php?id=' . $id); // a normal post
    }
    http_response_code(404);
    flash('error', 'That pet report no longer exists.');
    redirect(current_user() ? 'pets.php' : 'login.php');
}

// Share link / QR code: remember the token for this session (lets the viewer in even from far away).
$token = (string)($_GET['t'] ?? '');
$viaLink = $token !== '' ? pet_accept_share_token($pet, $token) : pet_link_access($id);
if ($token !== '' && !$viaLink) {
    flash('error', 'This share link is no longer valid. The owner may have turned sharing off.');
}

$me = current_user();
$guest = $me === null;
if ($guest && (!$viaLink || $pet['status'] !== 'active')) {
    require_login(); // remembers this page and comes back after login
}

$type = pet_type($pet['pet_type']);
$kindLabel = pet_kinds()[$pet['subtype']];
$isOwner = !$guest && (int)$pet['user_id'] === (int)$me['id'];
$isMod = !$guest && has_role('moderator');
$self = pet_url($id);
$petLabel = $pet['pet_name'] ?: ($pet['subtype'] === 'found' ? 'Unknown ' . strtolower($type['label']) : $type['label']);

if (!$guest) {
    [$allowed, $reason] = post_access($pet);
    if (!$allowed) {
        $pageTitle = 'Pet report unavailable';
        require __DIR__ . '/includes/header.php';
        $distance = post_distance($pet);
        $messages = [
            'removed'      => ['🚫', 'This report is no longer available', 'It was removed or is under review by moderators.'],
            'expired'      => ['⏳', 'This report has expired', ''],
            'no_location'  => ['📡', 'Share your location to view this report', 'Pet reports are visible to people near where the pet was seen, or to anyone with the share link.'],
            'out_of_range' => ['📍', 'You are outside this report\'s area', 'It is visible within ' . format_radius((int)$pet['radius_m'])
                . ' of where the pet was seen. You are about ' . format_distance((float)$distance) . ' away. Ask the owner for the share link.'],
        ][$reason] ?? ['🚫', 'Unavailable', ''];
        ?>
        <section class="card hero">
            <div class="hero-icon"><?= $messages[0] ?></div>
            <h1><?= e($messages[1]) ?></h1>
            <p><?= e($messages[2]) ?></p>
            <p><a class="btn btn-primary" href="<?= $reason === 'no_location' ? 'location.php' : 'pets.php' ?>">
                <?= $reason === 'no_location' ? 'Set my location' : 'Back to the pet board' ?></a></p>
        </section>
        <?php
        require __DIR__ . '/includes/footer.php';
        exit;
    }
}

$photos = pet_photos($id);
$sightings = pet_sightings($id, $isMod);
$sightingIds = array_map('intval', array_column($sightings, 'comment_id'));
$comments = $guest ? [] : q(
    "SELECT c.*, u.name AS author_name, u.role AS author_role
     FROM comments c JOIN users u ON u.id = c.user_id
     LEFT JOIN pet_sightings s ON s.comment_id = c.id
     WHERE c.post_id = ? AND s.comment_id IS NULL AND (c.status = 'active' OR ?)
     ORDER BY c.created_at ASC",
    [$id, $isMod ? 1 : 0]
)->fetchAll();
$open = in_array($pet['pet_status'], ['active', 'sighted'], true) && $pet['status'] === 'active';
$matches = (!$guest && $open) ? find_pet_matches($pet) : [];
$showContact = pet_contact_visible($pet, $guest);
$distance = $guest ? null : post_distance($pet);
$shareUrl = $pet['link_sharing'] ? pet_share_url($pet) : null;
$canSeeExact = $isOwner || $isMod;

// Map: report spot, sightings in time order (with a trail), the viewer.
[$plat, $plng] = pet_map_point($pet);
$markers = [['lat' => $plat, 'lng' => $plng, 'icon' => $type['icon'], 'cls' => 'pet-pin pet-pin-' . $pet['subtype'],
    'title' => ($pet['subtype'] === 'lost' ? 'Last seen ' : 'Found ') . time_ago($pet['seen_at'])]];
foreach ($sightings as $i => $s) {
    if ($s['status'] !== 'active') {
        continue;
    }
    $slat = $canSeeExact ? (float)$s['lat'] : fuzz_coord((float)$s['lat']);
    $slng = $canSeeExact ? (float)$s['lng'] : fuzz_coord((float)$s['lng']);
    $markers[] = ['lat' => $slat, 'lng' => $slng, 'icon' => '👀', 'cls' => 'pet-pin pet-pin-sighting',
        'title' => 'Sighting ' . ($i + 1) . ' · ' . time_ago($s['seen_at']), 'url' => '#s' . (int)$s['comment_id']];
}
$loc = $guest ? null : user_location();
$mapCfg = [
    'center' => [$plat, $plng], 'zoom' => 15,
    'circle' => ['center' => [$plat, $plng], 'radius' => (int)$pet['radius_m'], 'color' => '#f7923b'],
    'markers' => $markers,
    'me' => $loc ? [$loc['lat'], $loc['lng']] : null,
];

$pageTitle = $pet['title'];
$needsMap = true;
$extraScripts = ['https://unpkg.com/qrcode-generator@1.4.4/qrcode.js', 'assets/js/pets.js?v=1'];
require __DIR__ . '/includes/header.php';
?>
<p class="no-print"><a href="<?= $guest ? 'login.php' : 'pets.php' ?>" class="back-link"><?= icon('back') ?> <?= $guest ? 'Log in to ' . e(config('app_name')) : 'Lost pet board' ?></a></p>

<?php if ($pet['status'] !== 'active'): ?>
    <div class="flash flash-warning">This report is <strong><?= e($pet['status']) ?></strong> and not visible to the community.</div>
<?php endif; ?>
<?php if ($pet['pet_status'] === 'reunited'): ?>
    <div class="flash flash-success pet-reunited-banner">🎉 <?= e($petLabel) ?> has been reunited<?= $pet['status_changed_at'] ? ' ' . e(time_ago($pet['status_changed_at'])) : '' ?>. Thank you, neighbours!</div>
<?php endif; ?>

<div class="post-layout pet-page<?= $guest ? ' pet-guest' : '' ?>">
    <div>
        <article class="card post-full pet-full cat-lost_found">
            <header class="post-head">
                <?= avatar($pet['author_name'], '', (bool)$pet['is_anonymous']) ?>
                <div class="post-who">
                    <div class="post-author">
                        <?php if ($guest): ?>
                            <strong><?= $pet['is_anonymous'] ? 'Anonymous neighbour' : e($pet['author_name']) ?></strong>
                        <?php elseif ($pet['is_anonymous'] || $isOwner): ?>
                            <strong><?= e(author_label($pet)) ?></strong>
                        <?php else: ?>
                            <strong><a href="user.php?id=<?= (int)$pet['user_id'] ?>"><?= e($pet['author_name']) ?></a></strong>
                        <?php endif; ?>
                        <?php if ($isOwner): ?><span class="role-tag">You</span><?php endif; ?>
                    </div>
                    <div class="post-sub">
                        Reported <?= e(time_ago($pet['created_at'])) ?>
                        <?php if ($distance !== null): ?> · <?= e(format_distance($distance)) ?> away<?php endif; ?>
                        · visible within <?= e(format_radius((int)$pet['radius_m'])) ?>
                    </div>
                </div>
            </header>

            <div class="pet-badges">
                <?= pet_kind_badge($pet['subtype']) ?>
                <?= pet_status_badge($pet['pet_status']) ?>
                <span class="sub-tag"><?= $type['icon'] ?> <?= e($type['label']) ?></span>
            </div>

            <h1 class="post-title"><?= e($kindLabel) ?>: <?= e($petLabel) ?></h1>

            <?php if ($photos): ?>
                <div class="pet-gallery" data-gallery>
                    <img class="pet-gallery-main" src="<?= e(image_url($photos[0]['filename'])) ?>" alt="Photo of <?= e($petLabel) ?>" data-gallery-main>
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
                <div class="pet-nophoto" aria-hidden="true"><?= $type['icon'] ?></div>
            <?php endif; ?>

            <dl class="facts">
                <?php if ($pet['pet_name']): ?><dt>🏷 Name</dt><dd><?= e($pet['pet_name']) ?></dd><?php endif; ?>
                <dt><?= $type['icon'] ?> Animal</dt><dd><?= e($type['label']) ?><?= $pet['breed'] ? ' · ' . e($pet['breed']) : '' ?></dd>
                <dt>🎨 Colour</dt><dd><?= e($pet['color']) ?></dd>
                <?php if ($pet['sex'] !== 'unknown'): ?><dt>⚥ Sex</dt><dd><?= e(pet_sexes()[$pet['sex']]) ?></dd><?php endif; ?>
                <dt>🕒 <?= $pet['subtype'] === 'lost' ? 'Last seen' : 'Found' ?></dt>
                <dd><?= e(format_datetime($pet['seen_at'])) ?> <span class="muted">(<?= e(time_ago($pet['seen_at'])) ?>)</span></dd>
                <?php if ($pet['location_text']): ?><dt>📍 Where</dt><dd><?= e($pet['location_text']) ?></dd><?php endif; ?>
                <?php if ($showContact): ?>
                    <dt>📞 Contact</dt><dd><?= e($pet['contact']) ?>
                        <?php if ($isOwner): ?><span class="muted small">(shown to: <?= e(strtolower(strtok(pet_contact_visibilities()[$pet['contact_visibility']], '—,'))) ?>)</span><?php endif; ?></dd>
                <?php elseif ($pet['contact']): ?>
                    <dt>📞 Contact</dt><dd class="muted"><?= $guest && $pet['contact_visibility'] === 'members' ? 'Log in to see contact details' : 'Reach the owner through a sighting or comment below' ?></dd>
                <?php endif; ?>
            </dl>

            <div class="post-body"><?= render_text($pet['body']) ?></div>

            <?php if ($guest): ?>
                <div class="card pet-cta">
                    <p><strong>Have you seen <?= e($petLabel) ?>?</strong> Log in or create a free account to report a sighting with a photo and location.
                        The owner will be notified right away.</p>
                    <p><a class="btn btn-primary" href="login.php">Log in</a> <a class="btn" href="register.php">Create account</a></p>
                </div>
            <?php else: ?>
                <div class="post-actions no-print">
                    <?php if ($open && !$isOwner): ?>
                        <a class="btn btn-sm btn-primary" href="#sighting-form">👀 I saw this pet</a>
                    <?php endif; ?>
                    <?php if ($isOwner || $isMod): ?>
                        <form method="post" action="actions.php" class="inline" data-confirm="Delete this report permanently? Photos and sightings are deleted too.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_post">
                            <input type="hidden" name="post_id" value="<?= $id ?>">
                            <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                        </form>
                    <?php endif; ?>
                    <?php if (!$isOwner): ?>
                        <details class="report-box">
                            <summary class="btn btn-sm">⚑ Report</summary>
                            <form method="post" action="actions.php" class="form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="report">
                                <input type="hidden" name="post_id" value="<?= $id ?>">
                                <select name="reason" required>
                                    <?php foreach (report_reasons() as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?>
                                </select>
                                <textarea name="details" rows="2" maxlength="500" placeholder="Anything moderators should know? (optional)"></textarea>
                                <button class="btn btn-sm btn-danger" type="submit">Send report</button>
                            </form>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </article>

        <?php if ($isOwner || $isMod): ?>
            <section class="card pet-owner no-print">
                <h2><?= $isOwner ? 'Manage your report' : 'Moderator tools' ?></h2>
                <p class="small muted">Status: <?= pet_status_badge($pet['pet_status']) ?> <?= e(pet_statuses()[$pet['pet_status']]['hint']) ?></p>
                <div class="pet-status-actions">
                    <?php
                    $transitions = [
                        'reunited' => ['🎉 Mark as reunited', 'btn-success', 'Mark this pet as reunited? Everyone who helped will be notified.'],
                        'closed'   => ['Close report', '', 'Close this report? It will no longer show as active.'],
                        'active'   => ['Reopen as active', '', ''],
                    ];
                    foreach ($transitions as $st => [$label, $cls, $confirm]):
                        if ($st === $pet['pet_status']) continue; ?>
                        <form method="post" action="pet_actions.php" class="inline"<?= $confirm ? ' data-confirm="' . e($confirm) . '"' : '' ?>>
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="set_status">
                            <input type="hidden" name="post_id" value="<?= $id ?>">
                            <input type="hidden" name="status" value="<?= $st ?>">
                            <button class="btn btn-sm <?= $cls ?>" type="submit"><?= e($label) ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
                <?php if ($isOwner): ?>
                    <hr>
                    <h3>Privacy</h3>
                    <form method="post" action="pet_actions.php" class="form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="privacy">
                        <input type="hidden" name="post_id" value="<?= $id ?>">
                        <label>Show my contact details to
                            <select name="contact_visibility">
                                <?php foreach (pet_contact_visibilities() as $k => $l): ?>
                                    <option value="<?= e($k) ?>" <?= $pet['contact_visibility'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="check"><input type="checkbox" name="link_sharing" value="1" <?= $pet['link_sharing'] ? 'checked' : '' ?>> <span>Allow share link &amp; QR code</span></label>
                        <label class="check"><input type="checkbox" name="precise_location" value="1" <?= $pet['precise_location'] ? 'checked' : '' ?>> <span>Show the precise spot on the map</span></label>
                        <div class="form-actions"><button class="btn btn-sm" type="submit">Save privacy settings</button></div>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($matches): ?>
            <section class="card pet-matches no-print" id="matches">
                <h2>🔗 Possible matches</h2>
                <p class="small muted"><?= $pet['subtype'] === 'lost' ? 'Found' : 'Lost' ?> <?= e(strtolower($type['label'])) ?> reports within <?= pet_cfg('match_km') ?> km with a similar description.
                    Compare photos and details carefully.</p>
                <?php foreach ($matches as $m): $mLink = pet_match_link($m); ?>
                    <div class="pet-match">
                        <span class="pet-thumb pet-thumb-sm">
                            <?php if ($m['photo']): ?><img src="<?= e(image_url($m['photo'])) ?>" alt="" loading="lazy"><?php else: ?><span class="pet-thumb-emoji"><?= pet_type($m['pet_type'])['icon'] ?></span><?php endif; ?>
                        </span>
                        <div class="pet-match-main">
                            <div><?= pet_kind_badge($m['subtype']) ?> <strong>
                                <?php if ($mLink): ?><a href="<?= e($mLink) ?>"><?= e($m['title']) ?></a><?php else: ?><?= e($m['title']) ?><?php endif; ?>
                            </strong></div>
                            <div class="small muted"><?= e(implode(' · ', $m['match_reasons'])) ?> · <?= $m['subtype'] === 'lost' ? 'last seen' : 'found' ?> <?= e(time_ago($m['seen_at'])) ?></div>
                            <?php if (!$mLink): ?><div class="small muted">Outside your area. Comment here to ask its owner to share the link.</div><?php endif; ?>
                        </div>
                        <span class="pet-score" title="Similarity score"><?= (int)$m['match_score'] ?>%</span>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <section class="card pet-sightings" id="sightings">
            <h2>👀 Sightings (<?= count(array_filter($sightings, fn($s) => $s['status'] === 'active')) ?>)</h2>
            <?php if (!$sightings): ?><p class="muted">No sightings yet. <?= $open ? 'If you see this pet, report it below with a photo and location.' : '' ?></p><?php endif; ?>
            <?php foreach ($sightings as $i => $s): ?>
                <div class="pet-sighting<?= $s['status'] !== 'active' ? ' removed' : '' ?>" id="s<?= (int)$s['comment_id'] ?>">
                    <span class="pet-sighting-num"><?= $i + 1 ?></span>
                    <div class="pet-sighting-main">
                        <div class="comment-head">
                            <strong><?= $guest ? ($s['is_anonymous'] ? 'Anonymous neighbour' : 'A neighbour') : e(author_label($s)) ?></strong>
                            <span class="muted">saw it <?= e(time_ago($s['seen_at'])) ?> · <?= e(format_datetime($s['seen_at'])) ?></span>
                        </div>
                        <?php if ($s['location_text']): ?><div class="small">📍 <?= e($s['location_text']) ?></div><?php endif; ?>
                        <div class="small muted"><?= e(format_distance(haversine((float)$pet['lat'], (float)$pet['lng'], (float)$s['lat'], (float)$s['lng']))) ?> from the <?= $pet['subtype'] === 'lost' ? 'last-seen' : 'found' ?> spot</div>
                        <div class="comment-body"><?= render_text($s['body']) ?></div>
                        <?php if ($s['photo']): ?>
                            <a href="<?= e(image_url($s['photo'])) ?>" target="_blank" rel="noopener"><img class="pet-sighting-photo" src="<?= e(image_url($s['photo'])) ?>" alt="Sighting photo" loading="lazy"></a>
                        <?php endif; ?>
                        <?php if (!$guest): ?>
                            <div class="comment-tools">
                                <span>reported <?= e(time_ago($s['created_at'])) ?><?= $s['status'] !== 'active' ? ' · removed' : '' ?></span>
                                <?php if ((int)$s['user_id'] === (int)$me['id'] || $isMod): ?>
                                    <?php if ($s['status'] === 'active'): ?>
                                        <form method="post" action="actions.php" class="inline" data-confirm="Delete this sighting?">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_comment">
                                            <input type="hidden" name="comment_id" value="<?= (int)$s['comment_id'] ?>">
                                            <button class="linklike" type="submit">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <form method="post" action="actions.php" class="inline" data-confirm="Report this sighting to moderators?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="report">
                                        <input type="hidden" name="comment_id" value="<?= (int)$s['comment_id'] ?>">
                                        <input type="hidden" name="reason" value="inappropriate">
                                        <button class="linklike" type="submit">Report</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (!$guest && $open && !$isOwner): ?>
                <form method="post" action="pet_actions.php" enctype="multipart/form-data" class="form pet-sighting-form no-print" id="sighting-form">
                    <?= csrf_field() ?>
                    <?= honeypot_field() ?>
                    <input type="hidden" name="action" value="sighting">
                    <input type="hidden" name="post_id" value="<?= $id ?>">
                    <h3>Report a sighting</h3>
                    <p class="small muted">The owner is notified straight away. Tap the map where you saw the pet, or
                        <button class="linklike" type="button" data-fill-coords="#s_lat,#s_lng">📡 use my GPS position</button>.</p>
                    <?php $sCenter = $loc ? [$loc['lat'], $loc['lng']] : [$plat, $plng]; ?>
                    <div class="map map-sm" data-map='<?= e(json_encode([
                        'center' => [$plat, $plng], 'zoom' => 15,
                        'markers' => [$markers[0]],
                        'me' => $loc ? [$loc['lat'], $loc['lng']] : null,
                        'picker' => ['lat' => '#s_lat', 'lng' => '#s_lng', 'marker' => $sCenter],
                    ])) ?>'></div>
                    <input type="hidden" name="lat" id="s_lat" value="<?= e($sCenter[0]) ?>">
                    <input type="hidden" name="lng" id="s_lng" value="<?= e($sCenter[1]) ?>">
                    <div class="grid-2">
                        <label>When did you see it?
                            <input type="datetime-local" name="seen_at" value="<?= e(datetime_local()) ?>" max="<?= e(datetime_local(time() + 600)) ?>" required>
                        </label>
                        <label>Place / landmark (optional)
                            <input type="text" name="location_text" maxlength="190" placeholder="e.g. behind the school">
                        </label>
                    </div>
                    <label>What did you see?
                        <textarea name="body" rows="3" maxlength="2000" required minlength="3" placeholder="Which way was it heading? Did it look hurt? Is it still there?"></textarea>
                    </label>
                    <label>Photo (optional)
                        <input type="file" name="photo" accept="image/jpeg,image/png,image/gif,image/webp">
                    </label>
                    <div class="form-actions">
                        <label class="check"><input type="checkbox" name="is_anonymous" value="1"> <span>Hide my name</span></label>
                        <button class="btn btn-primary" type="submit">Send sighting</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <?php if (!$guest): ?>
            <section class="card comments no-print" id="comments">
                <h2>Comments (<?= count(array_filter($comments, fn($c) => $c['status'] === 'active')) ?>)</h2>
                <?php foreach ($comments as $c): ?>
                    <div class="comment<?= $c['status'] !== 'active' ? ' removed' : '' ?>" id="c<?= (int)$c['id'] ?>">
                        <?= avatar($c['author_name'], 'sm', (bool)$c['is_anonymous']) ?>
                        <div class="comment-main">
                            <div class="bubble">
                                <div class="comment-head">
                                    <strong><?= e(author_label($c)) ?></strong>
                                    <?php if ((int)$c['user_id'] === (int)$pet['user_id'] && !$pet['is_anonymous'] && !$c['is_anonymous']): ?><span class="role-tag">Owner</span><?php endif; ?>
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
                <?php if (!$comments): ?><p class="muted">No comments yet. Ask a question or offer help.</p><?php endif; ?>
                <?php if ($pet['status'] === 'active'): ?>
                    <form method="post" action="actions.php" class="form comment-form">
                        <?= csrf_field() ?>
                        <?= honeypot_field() ?>
                        <input type="hidden" name="action" value="comment">
                        <input type="hidden" name="post_id" value="<?= $id ?>">
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
        <?php endif; ?>
    </div>

    <aside class="no-print">
        <div class="card map-card">
            <div class="map map-sm" data-map='<?= e(json_encode($mapCfg)) ?>'></div>
            <p class="small muted"><?= $type['icon'] ?> = <?= $pet['subtype'] === 'lost' ? 'last seen' : 'found' ?>, 👀 = sightings.
                <?= $pet['precise_location'] || $canSeeExact ? '' : 'Locations are approximate (~100 m).' ?></p>
        </div>

        <div class="card pet-share">
            <h3>📣 Share this report</h3>
            <?php if ($shareUrl): ?>
                <p class="small muted">Anyone with this link or QR code can view the report and send sightings, even outside the radius.</p>
                <div class="pet-share-row">
                    <input type="text" readonly value="<?= e($shareUrl) ?>" data-share-url aria-label="Share link">
                    <button class="btn btn-sm" type="button" data-copy="[data-share-url]">Copy</button>
                </div>
                <div class="pet-qr" data-qr="<?= e($shareUrl) ?>" aria-label="QR code for the share link"></div>
                <div class="pet-share-actions">
                    <button class="btn btn-sm" type="button" data-qr-download="<?= e('elaka-pet-' . $id . '.gif') ?>">⬇ Download QR</button>
                    <button class="btn btn-sm" type="button" data-native-share data-title="<?= e($pet['title']) ?>" data-url="<?= e($shareUrl) ?>" hidden>Share…</button>
                    <button class="btn btn-sm" type="button" onclick="window.print()">🖨 Print poster</button>
                </div>
                <?php if ($isOwner): ?>
                    <form method="post" action="pet_actions.php" class="small" data-confirm="Create a new link? The old link and printed QR codes will stop working.">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="new_link">
                        <input type="hidden" name="post_id" value="<?= $id ?>">
                        <button class="linklike" type="submit">Reset link</button>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <p class="small muted">Link sharing is turned off<?= $isOwner ? '. Turn it on under Privacy to get a share link and QR code.' : ' by the owner. Only neighbours within the radius can see this report.' ?></p>
            <?php endif; ?>
        </div>
    </aside>
</div>

<?php // Printable poster (only visible when printing). ?>
<section class="pet-poster" aria-hidden="true">
    <h1><?= e(mb_strtoupper($kindLabel . ' ' . $type['label'])) ?></h1>
    <h2><?= e($petLabel) ?></h2>
    <?php if ($photos): ?><img src="<?= e(image_url($photos[0]['filename'])) ?>" alt=""><?php endif; ?>
    <p><strong><?= e(implode(' · ', array_filter([$pet['breed'], $pet['color'], $pet['sex'] !== 'unknown' ? pet_sexes()[$pet['sex']] : null]))) ?></strong></p>
    <p><?= $pet['subtype'] === 'lost' ? 'Last seen' : 'Found' ?>: <?= e(format_datetime($pet['seen_at'])) ?><?= $pet['location_text'] ? ' · ' . e($pet['location_text']) : '' ?></p>
    <p><?= e(excerpt($pet['body'], 400)) ?></p>
    <?php if ($pet['contact'] && $pet['contact_visibility'] !== 'hidden' && ($isOwner || $showContact)): ?><p class="pet-poster-contact">📞 <?= e($pet['contact']) ?></p><?php endif; ?>
    <?php if ($shareUrl): ?>
        <div class="pet-qr pet-qr-print" data-qr="<?= e($shareUrl) ?>"></div>
        <p class="small">Scan to see photos and report a sighting on <?= e(config('app_name')) ?></p>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
