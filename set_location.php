<?php
/**
 * Stores the viewer's location / radius in the session.
 * Called via fetch() from the browser Geolocation API, or by the manual map form.
 */
require __DIR__ . '/includes/bootstrap.php';

$user = current_user();
$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

if (!$user) {
    $isAjax ? json_response(['ok' => false, 'error' => 'login'], 401) : redirect('login.php');
}
if (!is_post()) {
    redirect('location.php');
}
verify_csrf();

$source = input('source', 'gps') === 'manual' ? 'manual' : 'gps';
$lat = $_POST['lat'] ?? null;
$lng = $_POST['lng'] ?? null;

if ($lat !== null && $lat !== '') {
    if ($source === 'manual' && !config('allow_manual_location')) {
        $isAjax ? json_response(['ok' => false, 'error' => 'Manual location is disabled.'], 403) : redirect('location.php');
    }
    if (!valid_coords($lat, $lng)) {
        $isAjax ? json_response(['ok' => false, 'error' => 'Invalid coordinates.'], 422) : redirect('location.php');
    }
    set_user_location((float)$lat, (float)$lng, $source);
    q('UPDATE users SET last_lat = ?, last_lng = ? WHERE id = ?', [(float)$lat, (float)$lng, $user['id']]);
}

// A typed custom radius (in km) wins over the preset chips.
$customKm = trim((string)input('radius_km', ''));
if ($customKm !== '' && is_numeric($customKm)) {
    $radius = (int)round((float)$customKm * 1000);
    if (!valid_viewer_radius($radius)) {
        $msg = 'Radius must be between ' . format_radius((int)config('radius_min')) . ' and ' . format_radius((int)config('radius_max')) . '.';
        if ($isAjax) {
            json_response(['ok' => false, 'error' => $msg], 422);
        }
        flash('error', $msg);
        redirect(safe_return(input('return'), 'location.php'));
    }
} else {
    $radius = (int)input('radius', '0');
}
if ($radius && valid_viewer_radius($radius)) {
    $_SESSION['radius'] = $radius;
}

if ($isAjax) {
    json_response(['ok' => true, 'location' => user_location()]);
}
flash('success', 'Location updated.');
redirect(safe_return(input('return'), 'index.php'));
