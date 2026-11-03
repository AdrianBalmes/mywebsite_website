<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$user = require_role('admin', 'teacher');
$today = today();
$weekday = clock()->format('N');

$classSql = "SELECT c.*,
        (SELECT COUNT(*) FROM class_students cs WHERE cs.class_id = c.id) AS enrolled,
        (SELECT COUNT(*) FROM student_attendance sa WHERE sa.class_id = c.id AND sa.session_date = ? AND sa.status IN ('present', 'late')) AS attended,
        (SELECT COUNT(*) FROM student_attendance sa WHERE sa.class_id = c.id AND sa.session_date = ? AND sa.status = 'late') AS late
    FROM classes c
    WHERE c.status = 'active' AND FIND_IN_SET(?, c.days) > 0";
$classParams = [$today, $today, $weekday];
if ($user['role'] === 'teacher') {
    $classSql .= ' AND c.teacher_id = ?';
    $classParams[] = $user['employee_id'] ?? 0;
}
$todaysClasses = query($classSql . ' ORDER BY c.start_time', $classParams)->fetchAll();

if ($user['role'] === 'admin') {
    $employeeTotal = (int) query("SELECT COUNT(*) FROM employees WHERE status = 'active'")->fetchColumn();
    $studentTotal = (int) query("SELECT COUNT(*) FROM students WHERE status = 'active'")->fetchColumn();
    $counts = query(
        "SELECT COUNT(CASE WHEN time_in IS NOT NULL THEN 1 END) AS clocked_in,
                COUNT(CASE WHEN status = 'late' THEN 1 END) AS late,
                COUNT(CASE WHEN time_in IS NOT NULL AND time_out IS NULL THEN 1 END) AS on_site,
                COUNT(CASE WHEN status = 'on_leave' THEN 1 END) AS on_leave
         FROM employee_attendance WHERE work_date = ?",
        [$today]
    )->fetch();
    $studentsIn = (int) query(
        "SELECT COUNT(DISTINCT student_id) FROM student_attendance WHERE session_date = ? AND status IN ('present', 'late')",
        [$today]
    )->fetchColumn();
    $recent = query(
        "SELECT * FROM (
            SELECT CONCAT(e.first_name, ' ', e.last_name) AS name, 'Employee' AS kind, a.time_in, a.time_out, a.status
            FROM employee_attendance a JOIN employees e ON e.id = a.employee_id
            WHERE a.work_date = ? AND a.time_in IS NOT NULL
            UNION ALL
            SELECT CONCAT(s.first_name, ' ', s.last_name), c.class_code, a.time_in, a.time_out, a.status
            FROM student_attendance a JOIN students s ON s.id = a.student_id JOIN classes c ON c.id = a.class_id
            WHERE a.session_date = ? AND a.time_in IS NOT NULL
         ) activity ORDER BY COALESCE(time_out, time_in) DESC LIMIT 10",
        [$today, $today]
    )->fetchAll();
}

$pageTitle = 'Dashboard';
require __DIR__ . '/includes/header.php';
?>
<p class="muted"><?= e(clock()->format('l, F j, Y')) ?><?= is_holiday($today) ? ' (holiday)' : '' ?></p>

<?php if ($user['role'] === 'teacher' && !$user['employee_id']): ?>
    <div class="alert alert-error" role="alert">Your login is not linked to an employee record, so no classes can be shown. Ask an administrator to link it.</div>
<?php endif; ?>

<?php if ($user['role'] === 'admin'): ?>
    <section class="cards">
        <div class="card"><span>Employees in</span><strong><?= (int) $counts['clocked_in'] ?> / <?= $employeeTotal ?></strong></div>
        <div class="card"><span>Employees late</span><strong><?= (int) $counts['late'] ?></strong></div>
        <div class="card"><span>On site now</span><strong><?= (int) $counts['on_site'] ?></strong></div>
        <div class="card"><span>On leave</span><strong><?= (int) $counts['on_leave'] ?></strong></div>
        <div class="card"><span>Students attended</span><strong><?= $studentsIn ?> / <?= $studentTotal ?></strong></div>
    </section>
<?php endif; ?>

<section class="panel">
    <h2><?= $user['role'] === 'teacher' ? 'My classes today' : 'Classes today' ?></h2>
    <?php if (!$todaysClasses): ?>
        <p class="muted">No classes scheduled today.</p>
    <?php else: ?>
        <div class="table-wrap"><table>
            <thead><tr><th>Time</th><th>Class</th><th>Room</th><th>Attended</th><th>Late</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($todaysClasses as $class): ?>
                <tr>
                    <td><?= e(fmt_time($class['start_time'])) ?> - <?= e(fmt_time($class['end_time'])) ?></td>
                    <td><?= e($class['class_code']) ?> <span class="muted"><?= e($class['subject']) ?></span></td>
                    <td><?= e($class['room']) ?></td>
                    <td><?= (int) $class['attended'] ?> / <?= (int) $class['enrolled'] ?></td>
                    <td><?= (int) $class['late'] ?></td>
                    <td class="actions"><a class="btn btn-sm" href="class_attendance.php?class_id=<?= (int) $class['id'] ?>&amp;date=<?= e($today) ?>">Take attendance</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>

<?php if ($user['role'] === 'admin'): ?>
    <section class="panel">
        <h2>Recent activity</h2>
        <?php if (!$recent): ?>
            <p class="muted">Nobody has timed in yet today.</p>
        <?php else: ?>
            <div class="table-wrap"><table>
                <thead><tr><th>Name</th><th>Type</th><th>Time in</th><th>Time out</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($recent as $row): ?>
                    <tr>
                        <td><?= e($row['name']) ?></td>
                        <td><?= e($row['kind']) ?></td>
                        <td><?= e(fmt_time($row['time_in'])) ?></td>
                        <td><?= e(fmt_time($row['time_out'])) ?></td>
                        <td><?= badge($row['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
