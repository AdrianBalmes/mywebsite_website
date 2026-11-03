<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

/** True once an account exists, which permanently locks this page. */
function already_installed(): bool
{
    try {
        return (bool) db()->query('SELECT 1 FROM users LIMIT 1')->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

function create_database(): void
{
    $server = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', config('db_host'), config('db_port')),
        config('db_user'),
        config('db_pass'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $name = str_replace('`', '', config('db_name'));
    $server->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $schema = file_get_contents(__DIR__ . '/database/schema.sql');
    foreach (preg_split('/;\s*\n/', $schema) as $statement) {
        if (trim($statement) !== '') {
            db()->exec($statement);
        }
    }
}

function load_sample_data(string $password): void
{
    $now = clock()->format('Y-m-d H:i:s');
    $today = today();

    foreach (['Faculty', 'Administration'] as $department) {
        query('INSERT IGNORE INTO departments (name) VALUES (?)', [$department]);
    }
    $departments = query('SELECT name, id FROM departments')->fetchAll(PDO::FETCH_KEY_PAIR);

    $employees = [
        ['EMP-1001', 'Maria', 'Santos', 'Faculty', 'Teacher'],
        ['EMP-1002', 'Jose', 'Reyes', 'Faculty', 'Teacher'],
        ['EMP-1003', 'Ana', 'Cruz', 'Administration', 'Registrar'],
    ];
    foreach ($employees as [$no, $first, $last, $department, $position]) {
        query(
            'INSERT INTO employees (employee_no, first_name, last_name, department_id, position, hire_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$no, $first, $last, $departments[$department], $position, $today, $now]
        );
    }

    $students = [
        ['STU-2001', 'Liza', 'Garcia'], ['STU-2002', 'Mark', 'Dela Cruz'], ['STU-2003', 'Ella', 'Ramos'],
        ['STU-2004', 'Paolo', 'Mendoza'], ['STU-2005', 'Nina', 'Torres'], ['STU-2006', 'Carlo', 'Bautista'],
    ];
    foreach ($students as [$no, $first, $last]) {
        query(
            "INSERT INTO students (student_no, first_name, last_name, year_level, section, created_at) VALUES (?, ?, ?, 'Grade 10', 'A', ?)",
            [$no, $first, $last, $now]
        );
    }

    $teacher = (int) query("SELECT id FROM employees WHERE employee_no = 'EMP-1001'")->fetchColumn();
    $classes = [
        ['MATH10-A', 'Mathematics 10', '08:00', '09:00', 'Room 101'],
        ['ENG10-A', 'English 10', '09:00', '10:00', 'Room 102'],
    ];
    foreach ($classes as [$code, $subject, $start, $end, $room]) {
        query(
            "INSERT INTO classes (class_code, subject, section, room, teacher_id, days, start_time, end_time, created_at) VALUES (?, ?, 'A', ?, ?, '1,2,3,4,5', ?, ?, ?)",
            [$code, $subject, $room, $teacher, $start, $end, $now]
        );
    }
    query("INSERT INTO class_students (class_id, student_id, enrolled_on) SELECT c.id, s.id, ? FROM classes c CROSS JOIN students s", [$today]);

    $hash = password_hash($password, PASSWORD_DEFAULT);
    query(
        "INSERT INTO users (username, password_hash, full_name, role, employee_id, created_at) VALUES ('teacher', ?, 'Maria Santos', 'teacher', ?, ?)",
        [$hash, $teacher, $now]
    );
    query(
        "INSERT INTO users (username, password_hash, full_name, role, created_at) VALUES ('kiosk', ?, 'Gate Kiosk', 'kiosk', ?)",
        [$hash, $now]
    );
    query(
        "INSERT INTO users (username, password_hash, full_name, role, student_id, created_at)
         SELECT student_no, ?, CONCAT(first_name, ' ', last_name), 'student', id, ? FROM students WHERE student_no = 'STU-2001'",
        [$hash, $now]
    );
    query(
        "INSERT INTO users (username, password_hash, full_name, role, employee_id, created_at)
         SELECT employee_no, ?, CONCAT(first_name, ' ', last_name), 'employee', id, ? FROM employees WHERE employee_no = 'EMP-1003'",
        [$hash, $now]
    );
}

$error = '';
$installed = false;
if (already_installed()) {
    $installed = true;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $fullName === '') {
        $error = 'Enter a username and your full name.';
    } elseif (strlen($password) < 8) {
        $error = 'The password must be at least 8 characters.';
    } elseif ($password !== ($_POST['password_confirm'] ?? '')) {
        $error = 'The passwords do not match.';
    } else {
        try {
            create_database();
            db()->beginTransaction();
            query(
                "INSERT INTO users (username, password_hash, full_name, role, created_at) VALUES (?, ?, ?, 'admin', ?)",
                [$username, password_hash($password, PASSWORD_DEFAULT), $fullName, clock()->format('Y-m-d H:i:s')]
            );
            if (!empty($_POST['sample_data'])) {
                load_sample_data($password);
            }
            db()->commit();
            flash('success', 'Installation complete. Log in with your new account.');
            redirect('index.php?as=admin');
        } catch (PDOException $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            error_log((string) $e);
            $error = 'Could not set up the database. Check that MySQL is running and that config/config.php is correct.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Install</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="auth-page">
<main class="auth-card">
    <h1>Attendance System Setup</h1>
    <?php if ($installed): ?>
        <p>This system is already installed.</p>
        <a class="btn btn-primary" href="index.php">Go to login</a>
    <?php else: ?>
        <p>This creates the database and your administrator account.</p>
        <?php if ($error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <form method="post" class="stack">
            <?= csrf_field() ?>
            <label>Your full name <input name="full_name" required maxlength="120" value="<?= e($_POST['full_name'] ?? '') ?>"></label>
            <label>Username <input name="username" required maxlength="50" autocomplete="username" value="<?= e($_POST['username'] ?? 'admin') ?>"></label>
            <label>Password <input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
            <label>Confirm password <input type="password" name="password_confirm" required minlength="8" autocomplete="new-password"></label>
            <label class="check"><input type="checkbox" name="sample_data" value="1"> Load sample data (logins "teacher", "kiosk", "STU-2001" and "EMP-1003" use the same password)</label>
            <button class="btn btn-primary" type="submit">Install</button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
