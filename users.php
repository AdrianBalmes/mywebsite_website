<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$admin = require_role('admin');
const ROLES = ['admin' => 'Administrator', 'teacher' => 'Teacher', 'employee' => 'Employee', 'student' => 'Student', 'kiosk' => 'Time clock kiosk'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);

    if (($_POST['action'] ?? '') === 'toggle') {
        if ($id === (int) $admin['id']) {
            flash('error', 'You cannot deactivate your own account.');
        } else {
            query('UPDATE users SET is_active = 1 - is_active WHERE id = ?', [$id]);
            log_action('user.toggle_active', "User #$id");
            flash('success', 'Account status updated.');
        }
        redirect('users.php');
    }

    $username = trim((string) ($_POST['username'] ?? ''));
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $role = (string) ($_POST['role'] ?? '');
    $employeeId = in_array($role, ['teacher', 'employee'], true) ? ((int) ($_POST['employee_id'] ?? 0) ?: null) : null;
    $studentId = $role === 'student' ? ((int) ($_POST['student_id'] ?? 0) ?: null) : null;
    $password = (string) ($_POST['password'] ?? '');
    $formUrl = $id ? "users.php?edit=$id" : 'users.php?new=1';

    $linkTaken = ($employeeId !== null || $studentId !== null) && query(
        'SELECT 1 FROM users WHERE (employee_id = ? OR student_id = ?) AND id <> ?',
        [$employeeId, $studentId, $id]
    )->fetchColumn();
    $error = match (true) {
        $username === '' || $fullName === '' => 'Username and full name are required.',
        !isset(ROLES[$role]) => 'Choose a role.',
        in_array($role, ['teacher', 'employee'], true) && $employeeId === null => 'Link this account to an employee record.',
        $role === 'student' && $studentId === null => 'Link this account to a student record.',
        (bool) $linkTaken => 'That person already has a login.',
        $id === (int) $admin['id'] && $role !== 'admin' => 'You cannot change your own role.',
        !$id && strlen($password) < 8, $password !== '' && strlen($password) < 8 => 'The password must be at least 8 characters.',
        default => null,
    };
    if ($error !== null) {
        flash('error', $error);
        redirect($formUrl);
    }

    try {
        if ($id) {
            query('UPDATE users SET username = ?, full_name = ?, role = ?, employee_id = ?, student_id = ? WHERE id = ?', [$username, $fullName, $role, $employeeId, $studentId, $id]);
            if ($password !== '') {
                query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
            }
            log_action('user.update', $username . ($password !== '' ? ' (password reset)' : ''));
        } else {
            query(
                'INSERT INTO users (username, password_hash, full_name, role, employee_id, student_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $role, $employeeId, $studentId, clock()->format('Y-m-d H:i:s')]
            );
            log_action('user.create', $username);
        }
    } catch (PDOException $e) {
        if (!is_duplicate_key($e)) {
            throw $e;
        }
        flash('error', 'That username is already taken.');
        redirect($formUrl);
    }
    flash('success', 'Account saved.');
    redirect('users.php');
}

if (isset($_GET['new']) || isset($_GET['edit'])) {
    $id = (int) ($_GET['edit'] ?? 0);
    $account = $id ? query('SELECT * FROM users WHERE id = ?', [$id])->fetch() : [];
    if ($id && !$account) {
        show_error(404, 'Not found', 'That account does not exist.');
    }
    $employees = query("SELECT id, employee_no, first_name, last_name FROM employees WHERE status = 'active' ORDER BY last_name, first_name")->fetchAll();
    $students = query("SELECT id, student_no, first_name, last_name FROM students WHERE status = 'active' ORDER BY last_name, first_name")->fetchAll();

    $pageTitle = $id ? 'Edit Account' : 'Add Account';
    require __DIR__ . '/includes/header.php';
    ?>
    <section class="panel">
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <label>Username <input name="username" required maxlength="50" autocomplete="off" value="<?= e($account['username'] ?? '') ?>"></label>
            <label>Full name <input name="full_name" required maxlength="120" value="<?= e($account['full_name'] ?? '') ?>"></label>
            <label>Role
                <select name="role" required>
                    <?php foreach (ROLES as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= ($account['role'] ?? 'teacher') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Linked employee (teacher and employee logins)
                <select name="employee_id">
                    <option value="">None</option>
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?= (int) $employee['id'] ?>"<?= (int) ($account['employee_id'] ?? 0) === (int) $employee['id'] ? ' selected' : '' ?>><?= e(full_name($employee) . ' (' . $employee['employee_no'] . ')') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="span-2">Linked student (student logins)
                <select name="student_id">
                    <option value="">None</option>
                    <?php foreach ($students as $student): ?>
                        <option value="<?= (int) $student['id'] ?>"<?= (int) ($account['student_id'] ?? 0) === (int) $student['id'] ? ' selected' : '' ?>><?= e(full_name($student) . ' (' . $student['student_no'] . ')') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="span-2"><?= $id ? 'New password (leave blank to keep the current one)' : 'Password' ?>
                <input type="password" name="password" minlength="8" autocomplete="new-password"<?= $id ? '' : ' required' ?>>
            </label>
            <div class="form-actions span-2">
                <button class="btn btn-primary" type="submit">Save</button>
                <a class="btn" href="users.php">Cancel</a>
            </div>
        </form>
    </section>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$accounts = query(
    'SELECT u.*, COALESCE(e.employee_no, s.student_no) AS linked_id FROM users u
     LEFT JOIN employees e ON e.id = u.employee_id
     LEFT JOIN students s ON s.id = u.student_id
     ORDER BY u.is_active DESC, u.username'
)->fetchAll();

$pageTitle = 'Users';
require __DIR__ . '/includes/header.php';
?>
<div class="toolbar"><a class="btn btn-primary push-right" href="users.php?new=1">Add account</a></div>
<section class="panel">
    <div class="table-wrap"><table>
        <thead><tr><th>Username</th><th>Name</th><th>Role</th><th>Linked ID</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($accounts as $account): ?>
            <tr>
                <td><?= e($account['username']) ?></td>
                <td><?= e($account['full_name']) ?></td>
                <td><?= e(ROLES[$account['role']]) ?></td>
                <td><?= e($account['linked_id']) ?></td>
                <td><?= badge($account['is_active'] ? 'active' : 'inactive') ?></td>
                <td class="actions">
                    <a class="btn btn-sm" href="users.php?edit=<?= (int) $account['id'] ?>">Edit</a>
                    <?php if ((int) $account['id'] !== (int) $admin['id']): ?>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $account['id'] ?>">
                            <button class="btn btn-sm" name="action" value="toggle"><?= $account['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
