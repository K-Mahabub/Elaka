<?php
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}

$email = '';
$error = '';
if (is_post()) {
    verify_csrf();
    $email = mb_strtolower(input('email'));
    $password = $_POST['password'] ?? '';

    // Simple brute-force throttle per session.
    $_SESSION['login_fails'] ??= [];
    $_SESSION['login_fails'] = array_filter($_SESSION['login_fails'], fn($t) => $t > time() - 600);
    if (count($_SESSION['login_fails']) >= 8) {
        $error = 'Too many failed attempts. Please wait a few minutes.';
    } else {
        $user = q('SELECT * FROM users WHERE email = ?', [$email])->fetch();
        if (!$user || !password_verify((string)$password, $user['password_hash'])) {
            $_SESSION['login_fails'][] = time();
            $error = 'Incorrect email or password.';
        } elseif ($user['is_banned']) {
            $error = 'This account has been suspended.';
        } else {
            login_user($user);
            unset($_SESSION['login_fails']);
            flash('success', 'Welcome back, ' . $user['name'] . '!');
            $next = $_SESSION['after_login'] ?? 'index.php';
            unset($_SESSION['after_login']);
            redirect(safe_return($next));
        }
    }
}

$pageTitle = 'Log in';
require __DIR__ . '/includes/header.php';
?>
<section class="auth-split">
    <div class="auth-brand">
        <img class="auth-logo" src="logo.png" alt="">
        <div class="wordmark">elaka</div>
        <h2>Elaka helps you see and share what's happening right around you.</h2>
    </div>
    <div class="auth-box">
        <div class="card auth-card">
            <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
            <form method="post" class="form">
                <?= csrf_field() ?>
                <input class="input-lg" type="email" name="email" value="<?= e($email) ?>" placeholder="Email address" aria-label="Email address" required autofocus>
                <input class="input-lg" type="password" name="password" placeholder="Password" aria-label="Password" required>
                <button class="btn btn-primary btn-xl btn-block" type="submit">Log in</button>
            </form>
            <hr>
            <div class="center"><a class="btn btn-green btn-lg" href="register.php">Create new account</a></div>
        </div>
        <p class="auth-note"><strong>Posts are local.</strong> You only see what's posted within your chosen radius.</p>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
