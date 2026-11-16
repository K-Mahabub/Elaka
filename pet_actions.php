<?php
/**
 * Pet report actions: submit a sighting, change status, privacy settings, reset the share link.
 * Comments, reports and deletion reuse actions.php.
 */
require __DIR__ . '/includes/bootstrap.php';
$me = require_login();

if (!is_post()) {
    redirect('pets.php');
}
verify_csrf();

$postId = (int)input('post_id');
$pet = get_pet_report($postId);
if (!$pet) {
    flash('error', 'That pet report no longer exists.');
    redirect('pets.php');
}
$back = pet_url($postId);
if (!can_access_post($pet)) {
    flash('error', 'You can only interact with pet reports in your area or opened through their share link.');
    redirect('pets.php');
}

$isOwner = (int)$pet['user_id'] === (int)$me['id'];
$isMod = has_role('moderator', $me);
$open = $pet['status'] === 'active' && in_array($pet['pet_status'], ['active', 'sighted'], true);

switch (input('action')) {
    case 'sighting':
        $body = input('body');
        $lat = $_POST['lat'] ?? null;
        $lng = $_POST['lng'] ?? null;
        $seenTs = strtotime(input('seen_at')) ?: null;
        $placeText = mb_substr(input('location_text'), 0, 190);
        $loc = user_location();
        $maxM = pet_cfg('max_report_km') * 1000;
        $error = null;

        if (!$open) {
            $error = 'This report is no longer accepting sightings.';
        } elseif ($isOwner) {
            $error = 'Add your own updates as a comment.';
        } elseif (honeypot_tripped()) {
            $error = 'Sighting rejected.';
        } elseif (mb_strlen($body) < 3 || mb_strlen($body) > 2000) {
            $error = 'Describe what you saw (3–2000 characters).';
        } elseif (count_links($body) > config('max_links_per_post')) {
            $error = 'Too many links in this sighting.';
        } elseif (!valid_coords($lat, $lng)) {
            $error = 'Mark where you saw the pet on the map.';
        } elseif (
            haversine((float)$pet['lat'], (float)$pet['lng'], (float)$lat, (float)$lng) > $maxM
            && !($loc && haversine($loc['lat'], $loc['lng'], (float)$lat, (float)$lng) <= $maxM)
        ) {
            $error = 'The sighting must be within ' . pet_cfg('max_report_km') . ' km of you or of the report.';
        } elseif (!$seenTs || $seenTs > time() + 600 || $seenTs < time() - 365 * 86400) {
            $error = 'Enter a valid time (not in the future).';
        } elseif (!$isMod && rate_limited('comments', (int)$me['id'], (int)config('rate_limit_comments'))) {
            $error = 'You are posting too quickly. Please slow down.';
        }

        $photo = null;
        if (!$error) {
            try {
                $photo = handle_image_upload('photo');
            } catch (RuntimeException $ex) {
                $error = $ex->getMessage();
            }
        }
        if ($error) {
            flash('error', $error);
            redirect($back . '#sighting-form');
        }

        $anon = input('is_anonymous') ? 1 : 0;
        $pdo = db();
        $pdo->beginTransaction();
        try {
            q('INSERT INTO comments (post_id, user_id, body, is_anonymous) VALUES (?, ?, ?, ?)', [$postId, $me['id'], $body, $anon]);
            $cid = (int)$pdo->lastInsertId();
            q(
                'INSERT INTO pet_sightings (comment_id, post_id, lat, lng, seen_at, location_text, photo) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$cid, $postId, (float)$lat, (float)$lng, date('Y-m-d H:i:s', min($seenTs, time())), $placeText ?: null, $photo]
            );
            if ($pet['pet_status'] === 'active') {
                pet_set_status($pet, 'sighted');
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            delete_image($photo);
            throw $ex;
        }

        $who = $anon ? 'Someone' : $me['name'];
        $label = $pet['pet_name'] ?: 'your ' . strtolower(pet_type($pet['pet_type'])['label']);
        $where = $placeText !== '' ? " near $placeText" : '';
        notify((int)$pet['user_id'], $postId, 'pet_sighting', "👀 $who reported a sighting of $label$where (" . time_ago(date('Y-m-d H:i:s', min($seenTs, time()))) . ').');
        flash('success', 'Thank you! The owner has been notified of your sighting.');
        redirect($back . '#s' . $cid);

    case 'set_status':
        $status = input('status');
        if ((!$isOwner && !$isMod) || !isset(pet_statuses()[$status])) {
            flash('error', 'Only the person who reported this pet can change its status.');
            break;
        }
        if ($status === $pet['pet_status']) {
            break;
        }
        pet_set_status($pet, $status);
        if ($status === 'reunited') {
            $n = pet_notify_helpers_reunited($pet);
            flash('success', '🎉 Wonderful news! Marked as reunited' . ($n ? " and $n helper(s) notified." : '.'));
        } else {
            if (!$isOwner) {
                notify((int)$pet['user_id'], $postId, 'pet_status', "🐾 A moderator set your report “{$pet['title']}” to " . pet_statuses()[$status]['label'] . '.');
            }
            flash('success', 'Status set to ' . pet_statuses()[$status]['label'] . '.');
        }
        break;

    case 'privacy':
        if (!$isOwner) {
            flash('error', 'Only the owner can change privacy settings.');
            break;
        }
        $vis = input('contact_visibility');
        q(
            'UPDATE pet_reports SET contact_visibility = ?, link_sharing = ?, precise_location = ? WHERE post_id = ?',
            [isset(pet_contact_visibilities()[$vis]) ? $vis : 'members', input('link_sharing') ? 1 : 0, input('precise_location') ? 1 : 0, $postId]
        );
        flash('success', 'Privacy settings saved.');
        break;

    case 'new_link':
        if (!$isOwner) {
            break;
        }
        q('UPDATE pet_reports SET share_token = ? WHERE post_id = ?', [bin2hex(random_bytes(16)), $postId]);
        flash('success', 'A new share link was created. The old link and QR codes no longer work.');
        break;

    default:
        flash('error', 'Unknown action.');
}

redirect($back);
