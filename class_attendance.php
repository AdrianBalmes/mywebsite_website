<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$user = require_role('admin', 'teacher');
$today = today();
$classId = (int) ($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
$date = $_GET['date'] ?? $_POST['date'] ?? $today;
if (!valid_date($date) || $date > $today) {
    $date = $today;
}

if ($classId === 0) {
    $classes = accessible_classes($user);
    $pageTitle = 'Class Attendance';
    require __DIR__ . '/includes/header.php';
    ?>
    <section class="panel">
        <?php if (!$classes): ?>
            <p class="muted">No classes are assigned to you yet.</p>
        <?php else: ?>
            <div class="table-wrap"><table>
                <thead><tr><th>Class</th><th>Subject</th><th>Teacher</th><th>Schedule</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($classes as $class): ?>
                    <tr>
                        <td><?= e($class['class_code']) ?></td>
                        <td><?= e($class['subject']) ?></td>
                        <td><?= $class['last_name'] ? e(full_name($class)) : '' ?></td>
                        <td><?= e(fmt_days($class['days'])) ?>, <?= e(fmt_time($class['start_time'])) ?> - <?= e(fmt_time($class['end_time'])) ?></td>
                        <td><?= badge($class['status']) ?></td>
                        <td class="actions"><a class="btn btn-sm" href="class_attendance.php?class_id=<?= (int) $class['id'] ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$class = query('SELECT * FROM classes WHERE id = ?', [$classId])->fetch();
if (!$class) {
    show_error(404, 'Not found', 'That class does not exist.');
}
if (!can_access_class($user, $class)) {
    show_error(403, 'Access denied', 'This class is not assigned to you.');
}

$roster = query(
    "SELECT s.id, s.student_no, s.first_name, s.last_name, a.status, a.time_in, a.time_out, a.late_minutes, a.remarks
     FROM class_students cs
     JOIN students s ON s.id = cs.student_id
     LEFT JOIN student_attendance a ON a.student_id = s.id AND a.class_id = cs.class_id AND a.session_date = ?
     WHERE cs.class_id = ? AND (s.status = 'active' OR a.id IS NOT NULL)
     ORDER BY s.last_name, s.first_name",
    [$date, $classId]
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $statuses = (array) ($_POST['status'] ?? []);
    $saves = [];
    foreach ($roster as $student) {
        $id = (int) $student['id'];
        $status = (string) ($statuses[$id] ?? '');
        if ($status === '') {
            continue;
        }
        if (!in_array($status, ['present', 'late', 'absent', 'excused'], true)) {
            show_error(400, 'Invalid status', 'That status is not recognised.');
        }

        $timeIn = trim((string) ($_POST['time_in'][$id] ?? ''));
        $timeOut = trim((string) ($_POST['time_out'][$id] ?? ''));
        $attended = in_array($status, ['present', 'late'], true);
        if (!$attended) {
            $timeIn = $timeOut = '';
        }
        if (($timeIn !== '' && !valid_time($timeIn)) || ($timeOut !== '' && !valid_time($timeOut))) {
            flash('error', 'Check the times entered for ' . full_name($student) . '. Nothing was saved.');
            redirect("class_attendance.php?class_id=$classId&date=$date");
        }
        if ($timeIn !== '' && $timeOut !== '' && $timeOut <= $timeIn) {
            flash('error', 'The time out for ' . full_name($student) . ' must be after the time in. Nothing was saved.');
            redirect("class_attendance.php?class_id=$classId&date=$date");
        }

        // Late minutes come from the time in when there is one, and are unknown when marked by hand.
        $lateMinutes = 0;
        if ($status === 'late' && $timeIn !== '') {
            $lateMinutes = max(0, minutes_between("$date {$class['start_time']}", "$date $timeIn:00"));
        }
        $saves[] = [
            $classId, $id, $date,
            $timeIn === '' ? null : "$date " . substr($timeIn, 0, 5) . ':00',
            $timeOut === '' ? null : "$date " . substr($timeOut, 0, 5) . ':00',
            $status, $lateMinutes,
            trim((string) ($_POST['remarks'][$id] ?? '')) ?: null,
            $user['id'],
        ];
    }

    // Blank times keep what is stored: a student may have scanned in after this page was opened.
    db()->beginTransaction();
    foreach ($saves as $save) {
        query(
            'INSERT INTO student_attendance (class_id, student_id, session_date, time_in, time_out, status, late_minutes, remarks, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                time_in = IF(VALUES(status) IN (\'absent\', \'excused\'), NULL, COALESCE(VALUES(time_in), time_in)),
                time_out = IF(VALUES(status) IN (\'absent\', \'excused\'), NULL, COALESCE(VALUES(time_out), time_out)),
                late_minutes = IF(VALUES(status) = \'late\', IF(VALUES(time_in) IS NULL, late_minutes, VALUES(late_minutes)), 0),
                status = VALUES(status), remarks = VALUES(remarks), updated_by = VALUES(updated_by)',
            $save
        );
    }
    log_action('class_attendance.save', "{$class['class_code']} on $date: " . count($saves) . ' students');
    db()->commit();

    flash('success', 'Attendance saved for ' . count($saves) . ' students.');
    redirect("class_attendance.php?class_id=$classId&date=$date");
}

$meetsOnDate = in_array((new DateTimeImmutable($date))->format('N'), explode(',', $class['days']), true);

$pageTitle = $class['class_code'] . ' Attendance';
require __DIR__ . '/includes/header.php';
?>
<p class="muted"><?= e($class['subject']) ?> · <?= e(fmt_days($class['days'])) ?>, <?= e(fmt_time($class['start_time'])) ?> - <?= e(fmt_time($class['end_time'])) ?></p>

<form class="filters">
    <input type="hidden" name="class_id" value="<?= $classId ?>">
    <label>Date <input type="date" name="date" value="<?= e($date) ?>" max="<?= e($today) ?>"></label>
    <button class="btn" type="submit">Show</button>
    <a class="btn" href="class_attendance.php">All classes</a>
</form>

<?php if (!$meetsOnDate): ?>
    <div class="alert alert-info" role="status">This class is not normally scheduled on <?= e(fmt_date($date)) ?>. You can still record a make-up session.</div>
<?php endif; ?>
<?php if (is_holiday($date)): ?>
    <div class="alert alert-info" role="status"><?= e(fmt_date($date)) ?> is a holiday.</div>
<?php endif; ?>

<section class="panel">
    <?php if (!$roster): ?>
        <p class="muted">No students are enrolled in this class.<?php if ($user['role'] === 'admin'): ?> <a href="class_students.php?class_id=<?= $classId ?>">Enrol students</a>.<?php endif; ?></p>
    <?php else: ?>
        <form method="post" data-roster-form>
            <?= csrf_field() ?>
            <input type="hidden" name="class_id" value="<?= $classId ?>">
            <input type="hidden" name="date" value="<?= e($date) ?>">
            <div class="toolbar">
                <button class="btn btn-sm" type="button" data-mark-all="present">Mark unmarked present</button>
                <button class="btn btn-sm" type="button" data-mark-all="absent">Mark unmarked absent</button>
            </div>
            <div class="table-wrap"><table>
                <thead><tr><th>Student</th><th>Status</th><th>Time in</th><th>Time out</th><th>Remarks</th></tr></thead>
                <tbody>
                <?php foreach ($roster as $student): $id = (int) $student['id']; ?>
                    <tr>
                        <td><?= e(full_name($student)) ?><br><span class="muted"><?= e($student['student_no']) ?></span></td>
                        <td>
                            <fieldset class="status-options">
                                <legend class="sr-only">Status for <?= e(full_name($student)) ?></legend>
                                <?php foreach (['present', 'late', 'absent', 'excused'] as $option): ?>
                                    <label><input type="radio" name="status[<?= $id ?>]" value="<?= $option ?>"<?= $student['status'] === $option ? ' checked' : '' ?>> <?= e(status_label($option)) ?></label>
                                <?php endforeach; ?>
                            </fieldset>
                        </td>
                        <td><input class="input-time" type="time" name="time_in[<?= $id ?>]" aria-label="Time in" value="<?= e($student['time_in'] ? date('H:i', strtotime($student['time_in'])) : '') ?>"></td>
                        <td><input class="input-time" type="time" name="time_out[<?= $id ?>]" aria-label="Time out" value="<?= e($student['time_out'] ? date('H:i', strtotime($student['time_out'])) : '') ?>"></td>
                        <td><input name="remarks[<?= $id ?>]" aria-label="Remarks" maxlength="255" value="<?= e($student['remarks']) ?>"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <div class="form-actions"><button class="btn btn-primary" type="submit">Save attendance</button></div>
        </form>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
