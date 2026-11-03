<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

// Generous because a whole computer lab can share one address.
const REGISTRATIONS_PER_HOUR = 60;

$type = ($_POST['person_type'] ?? $_GET['type'] ?? 'student') === 'employee' ? 'employee' : 'student';
$departments = query('SELECT id, name FROM departments ORDER BY name')->fetchAll();
$form = [];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $value = static fn (string $key) => trim((string) ($_POST[$key] ?? ''));
    $isStudent = $type === 'student';
    $form = [
        'id_number'      => $value('id_number'),
        'first_name'     => $value('first_name'),
        'last_name'      => $value('last_name'),
        'year_level'     => $isStudent ? ($value('year_level') ?: null) : null,
        'section'        => $isStudent ? ($value('section') ?: null) : null,
        'guardian_name'  => $isStudent ? ($value('guardian_name') ?: null) : null,
        'guardian_phone' => $isStudent ? ($value('guardian_phone') ?: null) : null,
        'department_id'  => $isStudent ? null : ((int) $value('department_id') ?: null),
        'position'       => $isStudent ? null : ($value('position') ?: null),
        'email'          => $isStudent ? null : ($value('email') ?: null),
        'phone'          => $isStudent ? null : ($value('phone') ?: null),
    ];

    $limits = [
        'id_number' => 30, 'first_name' => 60, 'last_name' => 60, 'year_level' => 30, 'section' => 30,
        'guardian_name' => 120, 'guardian_phone' => 30, 'position' => 100, 'email' => 120, 'phone' => 30,
    ];
    $tooLong = false;
    foreach ($limits as $field => $limit) {
        $tooLong = $tooLong || mb_strlen((string) $form[$field]) > $limit;
    }

    $password = (string) ($_POST['password'] ?? '');
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $since = clock()->modify('-1 hour')->format('Y-m-d H:i:s');
    $error = match (true) {
        $form['id_number'] === '' || $form['first_name'] === '' || $form['last_name'] === '' => 'ID number, first name and last name are required.',
        $tooLong => 'One of the entries is too long.',
        strlen($password) < 8 => 'The password must be at least 8 characters.',
        $password !== ($_POST['password_confirm'] ?? '') => 'The passwords do not match.',
        $form['email'] !== null && !filter_var($form['email'], FILTER_VALIDATE_EMAIL) => 'Enter a valid email address.',
        $form['department_id'] !== null && !in_array($form['department_id'], array_map('intval', array_column($departments, 'id')), true) => 'Choose a department from the list.',
        id_number_taken($form['id_number']), (bool) query('SELECT 1 FROM users WHERE username = ?', [$form['id_number']])->fetchColumn() => 'That ID number is already registered.',
        (bool) query("SELECT 1 FROM registrations WHERE id_number = ? AND status = 'pending'", [$form['id_number']])->fetchColumn() => 'That ID number is already waiting for approval.',
        (int) query('SELECT COUNT(*) FROM registrations WHERE ip_address = ? AND submitted_at >= ?', [$ip, $since])->fetchColumn() >= REGISTRATIONS_PER_HOUR => 'Too many registrations from this connection. Try again later.',
        default => '',
    };

    if ($error === '') {
        $columns = implode(', ', array_keys($form));
        query(
            "INSERT INTO registrations (person_type, $columns, password_hash, ip_address, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$type, ...array_values($form), password_hash($password, PASSWORD_DEFAULT), $ip, clock()->format('Y-m-d H:i:s')]
        );
        flash('success', 'Registration sent. Once an administrator approves it, you can log in with your ID number and this password, and your ID will work at the time clock.');
        redirect("register.php?type=$type");
    }
}
$flashes = take_flashes();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register - <?= e(setting('school_name')) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="auth-page">
<main class="auth-card auth-card-wide">
    <h1><?= $type === 'student' ? 'Student' : 'Employee' ?> Registration</h1>
    <p class="muted"><?= e(setting('school_name')) ?></p>

    <nav class="tabs" aria-label="Registration type">
        <a href="register.php?type=student"<?= $type === 'student' ? ' class="active" aria-current="page"' : '' ?>>Student</a>
        <a href="register.php?type=employee"<?= $type === 'employee' ? ' class="active" aria-current="page"' : '' ?>>Employee</a>
    </nav>

    <?php foreach ($flashes as $flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>
    <?php if ($error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>

    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="person_type" value="<?= e($type) ?>">
        <label class="span-2">ID number <input name="id_number" required maxlength="30" autocomplete="off" value="<?= e($form['id_number'] ?? '') ?>"></label>
        <label>First name <input name="first_name" required maxlength="60" autocomplete="given-name" value="<?= e($form['first_name'] ?? '') ?>"></label>
        <label>Last name <input name="last_name" required maxlength="60" autocomplete="family-name" value="<?= e($form['last_name'] ?? '') ?>"></label>
        <?php if ($type === 'student'): ?>
            <label>Year level <input name="year_level" maxlength="30" value="<?= e($form['year_level'] ?? '') ?>"></label>
            <label>Section <input name="section" maxlength="30" value="<?= e($form['section'] ?? '') ?>"></label>
            <label>Guardian name <input name="guardian_name" maxlength="120" value="<?= e($form['guardian_name'] ?? '') ?>"></label>
            <label>Guardian phone <input name="guardian_phone" maxlength="30" value="<?= e($form['guardian_phone'] ?? '') ?>"></label>
        <?php else: ?>
            <label>Department
                <select name="department_id">
                    <option value="">None</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= (int) $department['id'] ?>"<?= (int) ($form['department_id'] ?? 0) === (int) $department['id'] ? ' selected' : '' ?>><?= e($department['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Position <input name="position" maxlength="100" value="<?= e($form['position'] ?? '') ?>"></label>
            <label>Email <input type="email" name="email" maxlength="120" autocomplete="email" value="<?= e($form['email'] ?? '') ?>"></label>
            <label>Phone <input name="phone" maxlength="30" autocomplete="tel" value="<?= e($form['phone'] ?? '') ?>"></label>
        <?php endif; ?>
        <label>Password <input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
        <label>Confirm password <input type="password" name="password_confirm" required minlength="8" autocomplete="new-password"></label>
        <p class="muted span-2">You will log in with your ID number and this password.</p>
        <div class="form-actions span-2">
            <button class="btn btn-primary" type="submit">Register</button>
            <a class="btn" href="index.php?as=<?= e($type) ?>">Back to login</a>
        </div>
    </form>
</main>
</body>
</html>
