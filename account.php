<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$user = require_role('admin', 'teacher', 'student', 'employee');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new = (string) ($_POST['new_password'] ?? '');
    if (!password_verify((string) ($_POST['current_password'] ?? ''), $user['password_hash'])) {
        flash('error', 'Your current password is incorrect.');
    } elseif (strlen($new) < 8) {
        flash('error', 'The new password must be at least 8 characters.');
    } elseif ($new !== ($_POST['new_password_confirm'] ?? '')) {
        flash('error', 'The new passwords do not match.');
    } else {
        query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        log_action('account.change_password', $user['username']);
        flash('success', 'Password changed.');
    }
    redirect('account.php');
}

$details = [];
if ($user['student_id'] !== null) {
    $student = query('SELECT * FROM students WHERE id = ?', [$user['student_id']])->fetch();
    $details = [
        'ID number' => $student['student_no'], 'Name' => $student['first_name'] . ' ' . $student['last_name'],
        'Year level' => $student['year_level'], 'Section' => $student['section'],
        'Guardian' => $student['guardian_name'], 'Guardian phone' => $student['guardian_phone'],
    ];
} elseif ($user['employee_id'] !== null) {
    $employee = query(
        'SELECT e.*, d.name AS department FROM employees e LEFT JOIN departments d ON d.id = e.department_id WHERE e.id = ?',
        [$user['employee_id']]
    )->fetch();
    $details = [
        'ID number' => $employee['employee_no'], 'Name' => $employee['first_name'] . ' ' . $employee['last_name'],
        'Department' => $employee['department'], 'Position' => $employee['position'],
        'Email' => $employee['email'], 'Phone' => $employee['phone'],
        'Hire date' => fmt_date($employee['hire_date']),
        'Shift' => fmt_time($employee['work_start'] ?: setting('work_start')) . ' - ' . fmt_time($employee['work_end'] ?: setting('work_end')),
    ];
}

$pageTitle = 'My Account';
require __DIR__ . '/includes/header.php';
?>
<?php if ($details): ?>
    <section class="panel">
        <h2>My details</h2>
        <dl class="details">
            <?php foreach ($details as $label => $value): ?>
                <dt><?= e($label) ?></dt>
                <dd><?= $value ? e($value) : '<span class="muted">Not set</span>' ?></dd>
            <?php endforeach; ?>
        </dl>
        <p class="muted">Ask the administrator to correct anything here.</p>
    </section>
<?php endif; ?>

<section class="panel">
    <h2>Change password</h2>
    <p class="muted">Signed in as <?= e($user['username']) ?></p>
    <form method="post" class="stack narrow">
        <?= csrf_field() ?>
        <label>Current password <input type="password" name="current_password" required autocomplete="current-password"></label>
        <label>New password <input type="password" name="new_password" required minlength="8" autocomplete="new-password"></label>
        <label>Confirm new password <input type="password" name="new_password_confirm" required minlength="8" autocomplete="new-password"></label>
        <button class="btn btn-primary" type="submit">Change password</button>
    </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
