<?php
require __DIR__ . '/includes/bootstrap.php';

if (is_post()) {
    verify_csrf();
    logout_user();
    session_start();
    flash('info', 'You have been logged out.');
}
redirect('login.php');
