<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

try {
    $installed = (bool) db()->query('SELECT 1 FROM users LIMIT 1')->fetchColumn();
} catch (PDOException $e) {
    // Unknown database or missing table; anything else (MySQL stopped) should surface as an error.
    if (!in_array($e->errorInfo[1] ?? (int) $e->getCode(), [1049, 1146], true)) {
        throw $e;
    }
    $installed = false;
}
if (!$installed) {
    redirect('install.php');
}

$user = current_user();
if ($user) {
    redirect(home_page($user));
}

$as = $_POST['as'] ?? $_GET['as'] ?? 'student';
if (!is_string($as) || !isset(LOGIN_PORTALS[$as])) {
    $as = 'student';
}
$portal = LOGIN_PORTALS[$as];

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    if (login_locked($username, $ip)) {
        $error = 'Too many failed attempts. Try again in ' . LOGIN_LOCK_MINUTES . ' minutes.';
    } else {
        $user = attempt_login($username, (string) ($_POST['password'] ?? ''), $ip);
        if ($user === null) {
            $error = 'Incorrect ' . strtolower($portal['field']) . ' or password.';
        } elseif (!in_array($user['role'], $portal['roles'], true)) {
            $error = 'This account logs in on the ' . LOGIN_PORTALS[portal_for_role($user['role'])]['label'] . ' tab.';
        } else {
            start_session_for($user);
            redirect(home_page($user));
        }
    }
}
$flashes = take_flashes();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in - <?= e(setting('school_name')) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="auth-page">
<main class="auth-card">
    <h1><?= e(setting('school_name')) ?></h1>
    <p>Attendance Monitoring System</p>
    <nav class="tabs" aria-label="Login type">
        <?php foreach (LOGIN_PORTALS as $key => $option): ?>
            <a href="index.php?as=<?= e($key) ?>"<?= $key === $as ? ' class="active" aria-current="page"' : '' ?>><?= e($option['label']) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php foreach ($flashes as $flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>
    <?php if ($error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="as" value="<?= e($as) ?>">
        <label><?= e($portal['field']) ?> <input name="username" required autofocus autocomplete="username"></label>
        <label>Password <input type="password" name="password" required autocomplete="current-password"></label>
        <button class="btn btn-primary" type="submit">Log in as <?= e(strtolower($portal['label'])) ?></button>
    </form>
    <?php if ($as !== 'admin'): ?>
        <p class="auth-links">No account yet? <a href="register.php?type=<?= e($as) ?>">Register here</a></p>
    <?php endif; ?>
</main>
</body>
</html>
