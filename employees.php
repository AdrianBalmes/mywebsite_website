<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

require_role('admin');

$departments = query('SELECT id, name FROM departments ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);

    if (($_POST['action'] ?? '') === 'toggle') {
        query("UPDATE employees SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?", [$id]);
        log_action('employee.toggle_status', "Employee #$id");
        flash('success', 'Employee status updated.');
        redirect('employees.php');
    }

    $value = static fn (string $key) => trim((string) ($_POST[$key] ?? ''));
    $data = [
        'employee_no'   => $value('employee_no'),
        'first_name'    => $value('first_name'),
        'last_name'     => $value('last_name'),
        'department_id' => (int) $value('department_id') ?: null,
        'position'      => $value('position') ?: null,
        'email'         => $value('email') ?: null,
        'phone'         => $value('phone') ?: null,
        'hire_date'     => $value('hire_date') ?: null,
        'work_start'    => $value('work_start') ?: null,
        'work_end'      => $value('work_end') ?: null,
    ];
    $formUrl = $id ? "employees.php?edit=$id" : 'employees.php?new=1';

    $error = match (true) {
        $data['employee_no'] === '' || $data['first_name'] === '' || $data['last_name'] === '' => 'ID number, first name and last name are required.',
        $data['email'] !== null && !filter_var($data['email'], FILTER_VALIDATE_EMAIL) => 'Enter a valid email address.',
        $data['hire_date'] !== null && !valid_date($data['hire_date']) => 'Enter a valid hire date.',
        ($data['work_start'] === null) !== ($data['work_end'] === null) => 'Enter both schedule times, or leave both blank to use the default.',
        $data['work_start'] !== null && (!valid_time($data['work_start']) || !valid_time($data['work_end'])) => 'Enter valid schedule times.',
        id_used_by_other_table($data['employee_no'], 'students') => 'That ID number already belongs to a student.',
        default => null,
    };
    if ($error !== null) {
        $_SESSION['form'] = $data;
        flash('error', $error);
        redirect($formUrl);
    }

    try {
        if ($id) {
            $sets = implode(', ', array_map(static fn ($column) => "$column = ?", array_keys($data)));
            query("UPDATE employees SET $sets WHERE id = ?", [...array_values($data), $id]);
            log_action('employee.update', $data['employee_no']);
        } else {
            $columns = implode(', ', array_keys($data));
            query(
                "INSERT INTO employees ($columns, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [...array_values($data), clock()->format('Y-m-d H:i:s')]
            );
            log_action('employee.create', $data['employee_no']);
        }
    } catch (PDOException $e) {
        if (!is_duplicate_key($e)) {
            throw $e;
        }
        $_SESSION['form'] = $data;
        flash('error', 'That ID number is already used by another employee.');
        redirect($formUrl);
    }
    flash('success', 'Employee saved.');
    redirect('employees.php');
}

if (isset($_GET['new']) || isset($_GET['edit'])) {
    $id = (int) ($_GET['edit'] ?? 0);
    $employee = $id ? query('SELECT * FROM employees WHERE id = ?', [$id])->fetch() : [];
    if ($id && !$employee) {
        show_error(404, 'Not found', 'That employee does not exist.');
    }
    $employee = ($_SESSION['form'] ?? []) + $employee;
    unset($_SESSION['form']);

    $pageTitle = $id ? 'Edit Employee' : 'Add Employee';
    require __DIR__ . '/includes/header.php';
    ?>
    <section class="panel">
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <label>ID number <input name="employee_no" required maxlength="30" value="<?= e($employee['employee_no'] ?? '') ?>"></label>
            <label>Department
                <select name="department_id">
                    <option value="">None</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= (int) $department['id'] ?>"<?= (int) ($employee['department_id'] ?? 0) === (int) $department['id'] ? ' selected' : '' ?>><?= e($department['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>First name <input name="first_name" required maxlength="60" value="<?= e($employee['first_name'] ?? '') ?>"></label>
            <label>Last name <input name="last_name" required maxlength="60" value="<?= e($employee['last_name'] ?? '') ?>"></label>
            <label>Position <input name="position" maxlength="100" value="<?= e($employee['position'] ?? '') ?>"></label>
            <label>Hire date <input type="date" name="hire_date" value="<?= e($employee['hire_date'] ?? '') ?>"></label>
            <label>Email <input type="email" name="email" maxlength="120" value="<?= e($employee['email'] ?? '') ?>"></label>
            <label>Phone <input name="phone" maxlength="30" value="<?= e($employee['phone'] ?? '') ?>"></label>
            <label>Shift start <input type="time" name="work_start" value="<?= e(substr($employee['work_start'] ?? '', 0, 5)) ?>"></label>
            <label>Shift end <input type="time" name="work_end" value="<?= e(substr($employee['work_end'] ?? '', 0, 5)) ?>"></label>
            <p class="muted span-2">Leave the shift blank to use the default (<?= e(setting('work_start')) ?> to <?= e(setting('work_end')) ?>). A shift end earlier than its start is treated as an overnight shift.</p>
            <div class="form-actions span-2">
                <button class="btn btn-primary" type="submit">Save</button>
                <a class="btn" href="employees.php">Cancel</a>
            </div>
        </form>
    </section>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$search = trim((string) ($_GET['q'] ?? ''));
$departmentFilter = (int) ($_GET['department'] ?? 0);
$status = in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : 'active';

$where = 'e.status = ?';
$params = [$status];
if ($search !== '') {
    $where .= ' AND (e.employee_no LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ?)';
    array_push($params, "%$search%", "%$search%", "%$search%");
}
if ($departmentFilter) {
    $where .= ' AND e.department_id = ?';
    $params[] = $departmentFilter;
}
$total = (int) query("SELECT COUNT(*) FROM employees e WHERE $where", $params)->fetchColumn();
$employees = query(
    "SELECT e.*, d.name AS department FROM employees e LEFT JOIN departments d ON d.id = e.department_id
     WHERE $where ORDER BY e.last_name, e.first_name LIMIT " . PER_PAGE . ' OFFSET ' . page_offset(),
    $params
)->fetchAll();

$pageTitle = 'Employees';
require __DIR__ . '/includes/header.php';
?>
<form class="filters">
    <label>Search <input name="q" value="<?= e($search) ?>" placeholder="ID or name"></label>
    <label>Department
        <select name="department">
            <option value="0">All</option>
            <?php foreach ($departments as $department): ?>
                <option value="<?= (int) $department['id'] ?>"<?= $departmentFilter === (int) $department['id'] ? ' selected' : '' ?>><?= e($department['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Status
        <select name="status">
            <option value="active">Active</option>
            <option value="inactive"<?= $status === 'inactive' ? ' selected' : '' ?>>Inactive</option>
        </select>
    </label>
    <button class="btn" type="submit">Filter</button>
    <a class="btn btn-primary push-right" href="employees.php?new=1">Add employee</a>
</form>

<section class="panel">
    <?php if (!$employees): ?>
        <p class="muted">No employees found.</p>
    <?php else: ?>
        <div class="table-wrap"><table>
            <thead><tr><th>ID</th><th>Name</th><th>Department</th><th>Position</th><th>Shift</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($employees as $employee): ?>
                <tr>
                    <td><?= e($employee['employee_no']) ?></td>
                    <td><?= e(full_name($employee)) ?></td>
                    <td><?= e($employee['department']) ?></td>
                    <td><?= e($employee['position']) ?></td>
                    <td><?= $employee['work_start'] ? e(fmt_time($employee['work_start']) . ' - ' . fmt_time($employee['work_end'])) : 'Default' ?></td>
                    <td class="actions">
                        <a class="btn btn-sm" href="employees.php?edit=<?= (int) $employee['id'] ?>">Edit</a>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $employee['id'] ?>">
                            <button class="btn btn-sm" name="action" value="toggle"><?= $employee['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination($total) ?>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
