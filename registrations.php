<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$admin = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $now = clock()->format('Y-m-d H:i:s');

    db()->beginTransaction();
    // The lock stops a double click from creating the person twice.
    $registration = query("SELECT * FROM registrations WHERE id = ? AND status = 'pending' FOR UPDATE", [(int) ($_POST['id'] ?? 0)])->fetch();
    if (!$registration) {
        db()->rollBack();
        flash('error', 'That registration has already been reviewed.');
        redirect('registrations.php');
    }
    $person = $registration['id_number'] . ' ' . $registration['first_name'] . ' ' . $registration['last_name'];

    if ($action === 'approve') {
        if (id_number_taken($registration['id_number'])) {
            db()->rollBack();
            flash('error', "ID {$registration['id_number']} is already in use, so this registration cannot be approved. Reject it instead.");
            redirect('registrations.php');
        }
        if (query('SELECT 1 FROM users WHERE username = ?', [$registration['id_number']])->fetchColumn()) {
            db()->rollBack();
            flash('error', "A login named {$registration['id_number']} already exists, so this registration cannot be approved. Reject it instead.");
            redirect('registrations.php');
        }
        if ($registration['person_type'] === 'student') {
            query(
                'INSERT INTO students (student_no, first_name, last_name, year_level, section, guardian_name, guardian_phone, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $registration['id_number'], $registration['first_name'], $registration['last_name'], $registration['year_level'],
                    $registration['section'], $registration['guardian_name'], $registration['guardian_phone'], $now,
                ]
            );
            $message = 'Student approved. Enrol them in their classes so their scans work.';
        } else {
            // Starting the hire date today keeps reports from counting earlier days as absences.
            query(
                'INSERT INTO employees (employee_no, first_name, last_name, department_id, position, email, phone, hire_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $registration['id_number'], $registration['first_name'], $registration['last_name'], $registration['department_id'],
                    $registration['position'], $registration['email'], $registration['phone'], today(), $now,
                ]
            );
            $message = 'Employee approved. Their hire date is set to today; edit it on the Employees page if needed.';
        }
        $personId = (int) db()->lastInsertId();

        // Sign-ups made before logins existed have no password; add those from the Users page.
        if ($registration['password_hash'] !== null) {
            $link = $registration['person_type'] === 'student' ? 'student_id' : 'employee_id';
            query(
                "INSERT INTO users (username, password_hash, full_name, role, $link, created_at) VALUES (?, ?, ?, ?, ?, ?)",
                [
                    $registration['id_number'], $registration['password_hash'], $registration['first_name'] . ' ' . $registration['last_name'],
                    $registration['person_type'], $personId, $now,
                ]
            );
        } else {
            $message .= ' They have no login yet; add one from the Users page.';
        }
        $status = 'approved';
    } elseif ($action === 'reject') {
        $status = 'rejected';
        $message = 'Registration rejected.';
    } else {
        db()->rollBack();
        show_error(400, 'Invalid action', 'That action is not recognised.');
    }

    query('UPDATE registrations SET status = ?, password_hash = NULL, reviewed_by = ?, reviewed_at = ? WHERE id = ?', [$status, $admin['id'], $now, $registration['id']]);
    log_action("registration.$action", "{$registration['person_type']} $person");
    db()->commit();

    flash('success', $message);
    redirect('registrations.php');
}

$status = in_array($_GET['status'] ?? '', ['pending', 'approved', 'rejected'], true) ? $_GET['status'] : 'pending';
$total = (int) query('SELECT COUNT(*) FROM registrations WHERE status = ?', [$status])->fetchColumn();
$registrations = query(
    'SELECT r.*, d.name AS department, u.username AS reviewer
     FROM registrations r
     LEFT JOIN departments d ON d.id = r.department_id
     LEFT JOIN users u ON u.id = r.reviewed_by
     WHERE r.status = ? ORDER BY r.submitted_at DESC, r.id DESC LIMIT ' . PER_PAGE . ' OFFSET ' . page_offset(),
    [$status]
)->fetchAll();

$pageTitle = 'Registrations';
require __DIR__ . '/includes/header.php';
?>
<nav class="tabs" aria-label="Registration status">
    <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $key => $label): ?>
        <a href="registrations.php?status=<?= e($key) ?>"<?= $key === $status ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<section class="panel">
    <?php if (!$registrations): ?>
        <p class="muted">No <?= e($status) ?> registrations. New sign-ups come from <a href="register.php">the registration page</a>.</p>
    <?php else: ?>
        <div class="table-wrap"><table>
            <thead><tr><th>Submitted</th><th>Type</th><th>ID</th><th>Name</th><th>Details</th><th><?= $status === 'pending' ? '' : 'Reviewed' ?></th></tr></thead>
            <tbody>
            <?php foreach ($registrations as $registration): ?>
                <?php
                $details = $registration['person_type'] === 'student'
                    ? [$registration['year_level'], $registration['section'], $registration['guardian_name'], $registration['guardian_phone']]
                    : [$registration['department'], $registration['position'], $registration['email'], $registration['phone']];
                ?>
                <tr>
                    <td><?= e(fmt_datetime($registration['submitted_at'])) ?></td>
                    <td><?= e(status_label($registration['person_type'])) ?></td>
                    <td><?= e($registration['id_number']) ?></td>
                    <td><?= e(full_name($registration)) ?></td>
                    <td><?= e(implode(' · ', array_filter($details))) ?></td>
                    <?php if ($status === 'pending'): ?>
                        <td class="actions">
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $registration['id'] ?>">
                                <button class="btn btn-sm btn-primary" name="action" value="approve">Approve</button>
                                <button class="btn btn-sm btn-danger" name="action" value="reject" data-confirm="Reject this registration?">Reject</button>
                            </form>
                        </td>
                    <?php else: ?>
                        <td><?= e($registration['reviewer']) ?> <span class="muted"><?= e(fmt_datetime($registration['reviewed_at'])) ?></span></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination($total) ?>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
