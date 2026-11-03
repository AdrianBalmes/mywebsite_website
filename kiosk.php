<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$user = require_role('admin', 'kiosk');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['scan_result'] = record_scan((string) ($_POST['code'] ?? '')) + ['at' => clock()->format('g:i:s A')];
    redirect('kiosk.php');
}

$result = $_SESSION['scan_result'] ?? null;
unset($_SESSION['scan_result']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Time Clock - <?= e(setting('school_name')) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="kiosk-page">
<main class="kiosk" data-server-time="<?= clock()->getTimestamp() * 1000 ?>" data-time-zone="<?= e(config('timezone')) ?>">
    <p class="kiosk-school"><?= e(setting('school_name')) ?></p>
    <div class="kiosk-clock" id="kiosk-clock"><?= e(clock()->format('g:i:s A')) ?></div>
    <p class="kiosk-date"><?= e(clock()->format('l, F j, Y')) ?></p>

    <form method="post" class="kiosk-form">
        <?= csrf_field() ?>
        <label for="code">Scan or type your ID number</label>
        <input id="code" name="code" required autofocus autocomplete="off" maxlength="30">
        <button class="btn btn-primary" type="submit">Submit</button>
    </form>

    <div class="kiosk-result-slot" aria-live="polite">
        <?php if ($result): ?>
            <div class="kiosk-result kiosk-<?= e($result['type']) ?>" id="kiosk-result">
                <?php if ($result['name'] !== ''): ?><strong><?= e($result['name']) ?></strong><?php endif; ?>
                <span class="kiosk-message"><?= e($result['message']) ?></span>
                <?php if ($result['detail'] !== ''): ?><span><?= e($result['detail']) ?></span><?php endif; ?>
                <span class="kiosk-at"><?= e($result['at']) ?></span>
            </div>
        <?php endif; ?>
    </div>

    <div class="kiosk-foot">
        <?php if ($user['role'] === 'admin'): ?>
            <a href="dashboard.php">Back to dashboard</a>
        <?php endif; ?>
        <form method="post" action="logout.php">
            <?= csrf_field() ?>
            <button class="link-button" type="submit">Log out</button>
        </form>
    </div>
</main>
<script src="assets/app.js"></script>
</body>
</html>
