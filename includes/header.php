<?php
$navUser = current_user();
$navItems = [
    ['dashboard.php', 'Dashboard', ['admin', 'teacher']],
    ['my_attendance.php', 'Dashboard', ['student', 'employee']],
    ['my_attendance.php', 'My Attendance', ['teacher']],
    ['kiosk.php', 'Time Clock', ['admin']],
    ['employee_attendance.php', 'Employee Attendance', ['admin']],
    ['class_attendance.php', 'Class Attendance', ['admin', 'teacher']],
    ['reports.php', 'Reports', ['admin', 'teacher']],
    ['registrations.php', 'Registrations', ['admin']],
    ['employees.php', 'Employees', ['admin']],
    ['students.php', 'Students', ['admin']],
    ['classes.php', 'Classes', ['admin']],
    ['users.php', 'Users', ['admin']],
    ['settings.php', 'Settings', ['admin']],
    ['audit.php', 'Audit Log', ['admin']],
    ['account.php', 'My Account', ['admin', 'teacher', 'student', 'employee']],
];
$currentPage = basename($_SERVER['SCRIPT_NAME']);
$pendingRegistrations = $navUser['role'] === 'admin'
    ? (int) query("SELECT COUNT(*) FROM registrations WHERE status = 'pending'")->fetchColumn()
    : 0;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> - <?= e(setting('school_name')) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="brand"><?= e(setting('school_name')) ?></div>
        <nav>
            <?php foreach ($navItems as [$href, $label, $roles]): ?>
                <?php if (in_array($navUser['role'], $roles, true)): ?>
                    <a href="<?= e($href) ?>"<?= $href === $currentPage ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?><?php if ($href === 'registrations.php' && $pendingRegistrations > 0): ?> <span class="nav-count"><?= $pendingRegistrations ?></span><?php endif; ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <form method="post" action="logout.php" class="sidebar-foot">
            <?= csrf_field() ?>
            <span><?= e($navUser['full_name']) ?></span>
            <button class="btn btn-sm" type="submit">Log out</button>
        </form>
    </aside>
    <main class="content">
        <h1><?= e($pageTitle) ?></h1>
        <?php foreach (take_flashes() as $flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
        <?php endforeach; ?>
