<?php
/** Handles friend request actions: send, cancel, accept, decline, unfriend. */
require __DIR__ . '/includes/bootstrap.php';
$me = require_login();

if (!is_post()) {
    redirect('people.php');
}
verify_csrf();

$otherId = (int)input('user_id');
[$type, $message] = friend_action($me, $otherId, input('action'));
flash($type, $message);
redirect(safe_return(input('return'), 'user.php?id=' . $otherId));
