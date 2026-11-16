<?php
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}

$name = $email = '';
$errors = [];
if (is_post()) {
    verify_csrf();
    $name = input('name');
    $email = mb_strtolower(input('email'));
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['password_confirm'] ?? '');

    if (honeypot_tripped()) {
        $errors[] = 'Registration failed.';
    }
    if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
        $errors[] = 'Name must be 2–80 characters.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }
    if (!$errors && q('SELECT 1 FROM users WHERE email = ?', [$email])->fetchColumn()) {
        $errors[] = 'An account with that email already exists.';
    }

    if (!$errors) {
        q('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)', [$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        $user = q('SELECT * FROM users WHERE id = ?', [db()->lastInsertId()])->fetch();
        login_user($user);
        flash('success', 'Welcome to Elaka! Share your location to see your neighbourhood.');
        redirect('location.php');
    }
}

$pageTitle = 'Join';
require __DIR__ . '/includes/header.php';
?>
<section class="auth-stack">
    <div class="wordmark">elaka</div>
    <div class="card auth-card wide">
        <div class="auth-card-head">
            <h1>Create a new account</h1>
            <p class="muted">It's quick and easy.</p>
        </div>
        <?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach; ?>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <?= honeypot_field() ?>
            <input type="text" name="name" value="<?= e($name) ?>" maxlength="80" placeholder="Display name" aria-label="Display name" required autofocus>
            <input type="email" name="email" value="<?= e($email) ?>" placeholder="Email address" aria-label="Email address" required>
            <input type="password" name="password" minlength="8" placeholder="New password (8+ characters)" aria-label="Password" required>
            <input type="password" name="password_confirm" minlength="8" placeholder="Confirm password" aria-label="Confirm password" required>
            <p class="tiny muted">Elaka shows you posts based on <em>where you are</em>, not who you follow. Your exact location is never shown to other people.</p>
            <div class="center"><button class="btn btn-green btn-lg btn-wide" type="submit">Sign up</button></div>
        </form>
        <p class="center"><a href="login.php">Already have an account?</a></p>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
