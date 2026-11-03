<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$user = require_role('admin');

$date = $_GET['date'] ?? today();
if (!valid_date($date)) {
    $date = today();
}

function load_employee(int $id): array
{
    $employee = query('SELECT * FROM employees WHERE id = ?', [$id])->fetch();
    if (!$employee) {
        show_error(404, 'Not found', 'That employee does not exist.');
    }
    return $employee;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $employee = load_employee((int) ($_POST['employee_id'] ?? 0));
    $workDate = (string) ($_POST['work_date'] ?? '');
    $back = 'employee_attendance.php?date=' . urlencode($workDate);
    if (!valid_date($workDate) || $workDate > today()) {
        show_error(400, 'Invalid date', 'Attendance can only be recorded for today or earlier.');
    }
    $editUrl = "employee_attendance.php?edit={$employee['id']}&date=$workDate";
    $label = full_name($employee) . ' on ' . $workDate;

    if (($_POST['action'] ?? '') === 'delete') {
        query('DELETE FROM employee_attendance WHERE employee_id = ? AND work_date = ?', [$employee['id'], $workDate]);
        log_action('employee_attendance.delete', $label);
        flash('success', 'Record removed.');
        redirect($back);
    }

    $mode = (string) ($_POST['status'] ?? 'auto');
    $remarks = trim((string) ($_POST['remarks'] ?? '')) ?: null;
    $fields = ['time_in' => null, 'time_out' => null, 'status' => $mode, 'late_minutes' => 0, 'undertime_minutes' => 0, 'worked_minutes' => 0];

    if ($mode === 'auto') {
        $timeIn = parse_datetime($_POST['time_in'] ?? '');
        $timeOutInput = (string) ($_POST['time_out'] ?? '');
        $timeOut = $timeOutInput === '' ? null : parse_datetime($timeOutInput);
        $error = match (true) {
            $timeIn === null => 'Enter a valid time in.',
            $timeOutInput !== '' && $timeOut === null => 'Enter a valid time out.',
            substr($timeIn, 0, 10) !== $workDate => 'The time in must fall on the attendance date.',
            $timeOut !== null && $timeOut <= $timeIn => 'The time out must be after the time in.',
            $timeOut !== null && minutes_between($timeIn, $timeOut) > 24 * 60 => 'The time out must be within 24 hours of the time in.',
            default => null,
        };
        if ($error !== null) {
            flash('error', $error);
            redirect($editUrl);
        }
        $fields = ['time_in' => $timeIn, 'time_out' => $timeOut] + employee_metrics($employee, $workDate, $timeIn, $timeOut);
    } elseif (!in_array($mode, ['absent', 'on_leave'], true)) {
        show_error(400, 'Invalid status', 'That status is not recognised.');
    }

    query(
        'INSERT INTO employee_attendance
            (employee_id, work_date, time_in, time_out, status, late_minutes, undertime_minutes, worked_minutes, remarks, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE time_in = VALUES(time_in), time_out = VALUES(time_out), status = VALUES(status),
            late_minutes = VALUES(late_minutes), undertime_minutes = VALUES(undertime_minutes),
            worked_minutes = VALUES(worked_minutes), remarks = VALUES(remarks), updated_by = VALUES(updated_by)',
        [
            $employee['id'], $workDate, $fields['time_in'], $fields['time_out'], $fields['status'],
            $fields['late_minutes'], $fields['undertime_minutes'], $fields['worked_minutes'], $remarks, $user['id'],
        ]
    );
    log_action('employee_attendance.save', $label . ': ' . $fields['status'] . ' ' . ($fields['time_in'] ?? '') . ' - ' . ($fields['time_out'] ?? ''));
    flash('success', 'Attendance saved.');
    redirect($back);
}

if (isset($_GET['edit'])) {
    $employee = load_employee((int) $_GET['edit']);
    $record = query('SELECT * FROM employee_attendance WHERE employee_id = ? AND work_date = ?', [$employee['id'], $date])->fetch();
    $isAuto = !$record || $record['time_in'] !== null;

    $pageTitle = 'Correct Attendance';
    require __DIR__ . '/includes/header.php';
    ?>
    <section class="panel">
        <h2><?= e(full_name($employee)) ?> <span class="muted"><?= e(fmt_date($date)) ?></span></h2>
        <form method="post" class="form-grid" data-attendance-form>
            <?= csrf_field() ?>
            <input type="hidden" name="employee_id" value="<?= (int) $employee['id'] ?>">
            <input type="hidden" name="work_date" value="<?= e($date) ?>">
            <label>Record as
                <select name="status" data-status-select>
                    <option value="auto"<?= $isAuto ? ' selected' : '' ?>>Worked (present or late is worked out from the times)</option>
                    <option value="absent"<?= ($record['status'] ?? '') === 'absent' ? ' selected' : '' ?>>Absent</option>
                    <option value="on_leave"<?= ($record['status'] ?? '') === 'on_leave' ? ' selected' : '' ?>>On leave</option>
                </select>
            </label>
            <label data-time-field>Time in
                <input type="datetime-local" name="time_in" value="<?= e($record['time_in'] ?? null ? date('Y-m-d\TH:i', strtotime($record['time_in'])) : '') ?>">
            </label>
            <label data-time-field>Time out
                <input type="datetime-local" name="time_out" value="<?= e($record['time_out'] ?? null ? date('Y-m-d\TH:i', strtotime($record['time_out'])) : '') ?>">
            </label>
            <label class="span-2">Remarks <input name="remarks" maxlength="255" value="<?= e($record['remarks'] ?? '') ?>"></label>
            <div class="form-actions span-2">
                <button class="btn btn-primary" type="submit">Save</button>
                <a class="btn" href="employee_attendance.php?date=<?= e($date) ?>">Cancel</a>
                <?php if ($record): ?>
                    <button class="btn btn-danger" type="submit" name="action" value="delete" formnovalidate data-confirm="Remove this attendance record?">Remove record</button>
                <?php endif; ?>
            </div>
        </form>
    </section>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$search = trim((string) ($_GET['q'] ?? ''));
$where = "(e.status = 'active' OR a.id IS NOT NULL)";
$params = [$date];
if ($search !== '') {
    $where .= ' AND (e.employee_no LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ?)';
    array_push($params, "%$search%", "%$search%", "%$search%");
}
$rows = query(
    "SELECT e.id, e.employee_no, e.first_name, e.last_name, e.hire_date, d.name AS department, a.time_in, a.time_out,
            a.status, a.late_minutes, a.undertime_minutes, a.worked_minutes, a.remarks
     FROM employees e
     LEFT JOIN departments d ON d.id = e.department_id
     LEFT JOIN employee_attendance a ON a.employee_id = e.id AND a.work_date = ?
     WHERE $where ORDER BY e.last_name, e.first_name",
    $params
)->fetchAll();

$pageTitle = 'Employee Attendance';
require __DIR__ . '/includes/header.php';
?>
<form class="filters">
    <label>Date <input type="date" name="date" value="<?= e($date) ?>" max="<?= e(today()) ?>"></label>
    <label>Search <input name="q" value="<?= e($search) ?>" placeholder="ID or name"></label>
    <button class="btn" type="submit">Show</button>
</form>

<section class="panel">
    <?php if (!$rows): ?>
        <p class="muted">No employees found. <a href="employees.php?new=1">Add an employee</a>.</p>
    <?php else: ?>
    <div class="table-wrap"><table>
        <thead><tr><th>ID</th><th>Name</th><th>Department</th><th>Time in</th><th>Time out</th><th>Status</th><th>Late</th><th>Undertime</th><th>Worked</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= e($row['employee_no']) ?></td>
                <td><?= e(full_name($row)) ?></td>
                <td><?= e($row['department']) ?></td>
                <td><?= e(fmt_datetime($row['time_in'])) ?></td>
                <td><?= e(fmt_datetime($row['time_out'])) ?></td>
                <td><?= $row['status'] ? badge($row['status']) : badge(expected_status($date, $row['hire_date'])) ?></td>
                <td><?= e(fmt_minutes((int) $row['late_minutes'])) ?></td>
                <td><?= e(fmt_minutes((int) $row['undertime_minutes'])) ?></td>
                <td><?= e(fmt_minutes((int) $row['worked_minutes'])) ?></td>
                <td class="actions"><a class="btn btn-sm" href="employee_attendance.php?edit=<?= (int) $row['id'] ?>&amp;date=<?= e($date) ?>">Edit</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
