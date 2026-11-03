<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/reports.php';

$user = require_role('student', 'employee', 'teacher');

$from = valid_date($_GET['from'] ?? null) ? $_GET['from'] : clock()->format('Y-m-01');
$to = valid_date($_GET['to'] ?? null) ? $_GET['to'] : today();

$error = '';
if ($from > $to) {
    $error = 'The start date must not be after the end date.';
} elseif ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > MAX_REPORT_DAYS) {
    $error = 'Choose a range of ' . MAX_REPORT_DAYS . ' days or less.';
}

// The person always comes from the login, never from the request, so nobody can open another record.
$summaryHeaders = $summaryRows = $logHeaders = $logRows = $subjects = [];
$person = null;
$isStudent = $user['student_id'] !== null;
if ($isStudent) {
    $person = query('SELECT * FROM students WHERE id = ?', [$user['student_id']])->fetch();
    $subjects = student_subjects((int) $user['student_id']);
    if ($error === '') {
        [$summaryHeaders, $summaryRows, $logHeaders, $logRows] = student_attendance_record((int) $user['student_id'], $from, $to);
    }
} elseif ($user['employee_id'] !== null) {
    $person = query(
        'SELECT e.*, d.name AS department FROM employees e LEFT JOIN departments d ON d.id = e.department_id WHERE e.id = ?',
        [$user['employee_id']]
    )->fetch();
    if ($error === '') {
        [$summaryHeaders, $summaryRows, $logHeaders, $logRows] = employee_attendance_record($person, $from, $to);
    }
}

if (($_GET['export'] ?? '') === 'csv' && $logHeaders) {
    export_csv("my_attendance_{$from}_$to.csv", $logHeaders, $logRows);
}

$isEmployee = !$isStudent && $person !== null;
$current = $isEmployee ? employee_current_record((int) $person['id']) : null;

$totals = ['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0];
if ($isStudent) {
    foreach ($summaryRows as $row) {
        $totals['present'] += $row[2];
        $totals['late'] += $row[3];
        $totals['absent'] += $row[4];
        $totals['excused'] += $row[5];
    }
}

$pageTitle = match ($user['role']) {
    'student'  => 'Student Dashboard',
    'employee' => 'Employee Dashboard',
    default    => 'My Attendance',
};
require __DIR__ . '/includes/header.php';
?>
<?php if ($person === null): ?>
    <div class="alert alert-error" role="alert">Your login is not linked to a student or employee record. Ask an administrator to link it.</div>
<?php else: ?>
    <p class="muted">
        <?= e($person['first_name'] . ' ' . $person['last_name']) ?> · <?= e($person['student_no'] ?? $person['employee_no']) ?>
        <?php if ($isStudent && $person['year_level']): ?> · <?= e($person['year_level']) ?><?php endif; ?>
        <?php if ($isStudent && $person['section']): ?> · <?= e($person['section']) ?><?php endif; ?>
        <?php if ($isEmployee && $person['department']): ?> · <?= e($person['department']) ?><?php endif; ?>
        <?php if ($isEmployee && $person['position']): ?> · <?= e($person['position']) ?><?php endif; ?>
    </p>

    <div class="print-only">
        <h2><?= e(setting('school_name')) ?>: Attendance Record</h2>
        <p><?= e(fmt_date($from)) ?> to <?= e(fmt_date($to)) ?></p>
    </div>

    <?php if ($isEmployee): ?>
        <section class="panel no-print">
            <h2>Today</h2>
            <div class="cards cards-compact">
                <div class="card"><span>My shift</span><strong><?= e(fmt_time($person['work_start'] ?: setting('work_start'))) ?> - <?= e(fmt_time($person['work_end'] ?: setting('work_end'))) ?></strong></div>
                <div class="card"><span>Time in</span><strong><?= e(fmt_time($current['time_in'] ?? null) ?: '-') ?></strong></div>
                <div class="card"><span>Time out</span><strong><?= e(fmt_time($current['time_out'] ?? null) ?: '-') ?></strong></div>
                <div class="card"><span>Status</span><strong><?= badge($current['status'] ?? expected_status(today(), $person['hire_date'])) ?></strong></div>
                <div class="card"><span>Worked</span><strong><?= e(fmt_minutes((int) ($current['worked_minutes'] ?? 0))) ?></strong></div>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($isStudent): ?>
        <section class="panel">
            <h2>My subjects (<?= count($subjects) ?>)</h2>
            <?php if (!$subjects): ?>
                <p class="muted">You are not enrolled in any subjects yet. Ask the administrator to enrol you.</p>
            <?php else: ?>
                <div class="table-wrap"><table>
                    <thead><tr><th>Class</th><th>Subject</th><th>Schedule</th><th>Room</th><th>Teacher</th><th>Today</th></tr></thead>
                    <tbody>
                    <?php foreach ($subjects as $subject): ?>
                        <tr>
                            <td><?= e($subject['class_code']) ?></td>
                            <td><?= e($subject['subject']) ?></td>
                            <td><?= e(fmt_days($subject['days'])) ?><br><span class="muted"><?= e(fmt_time($subject['start_time'])) ?> - <?= e(fmt_time($subject['end_time'])) ?></span></td>
                            <td><?= e($subject['room']) ?></td>
                            <td><?= $subject['last_name'] ? e($subject['first_name'] . ' ' . $subject['last_name']) : '<span class="muted">Not assigned</span>' ?></td>
                            <td>
                                <?= $subject['today_status'] ? badge($subject['today_status']) : ($subject['meets_today'] ? badge('not_marked') : '<span class="muted">No class</span>') ?>
                                <?php if ($subject['today_in']): ?>
                                    <br><span class="muted"><?= e(fmt_time($subject['today_in'])) ?><?= $subject['today_out'] ? ' - ' . e(fmt_time($subject['today_out'])) : '' ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <form class="filters no-print">
        <label>From <input type="date" name="from" value="<?= e($from) ?>"></label>
        <label>To <input type="date" name="to" value="<?= e($to) ?>"></label>
        <button class="btn" type="submit">Show</button>
        <?php if ($logRows): ?>
            <button class="btn" type="submit" name="export" value="csv">Export CSV</button>
            <button class="btn" type="button" data-print>Print</button>
        <?php endif; ?>
    </form>

    <?php if ($error): ?>
        <div class="alert alert-error" role="alert"><?= e($error) ?></div>
    <?php else: ?>
        <?php if ($isStudent && $summaryRows): ?>
            <section class="cards">
                <div class="card"><span>Present</span><strong><?= $totals['present'] ?></strong></div>
                <div class="card"><span>Late</span><strong><?= $totals['late'] ?></strong></div>
                <div class="card"><span>Absent</span><strong><?= $totals['absent'] ?></strong></div>
                <div class="card"><span>Excused</span><strong><?= $totals['excused'] ?></strong></div>
                <div class="card"><span>Attendance rate</span><strong><?= e(attendance_rate($totals)) ?></strong></div>
            </section>
        <?php endif; ?>

        <?php if ($isEmployee && $summaryRows): ?>
            <section class="cards">
                <?php foreach ($summaryHeaders as $index => $header): ?>
                    <div class="card"><span><?= e($header) ?></span><strong><?= e((string) $summaryRows[0][$index]) ?></strong></div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <?php if ($isStudent && $summaryRows): ?>
            <section class="panel">
                <h2>Attendance by subject</h2>
                <div class="table-wrap"><table>
                    <thead><tr><?php foreach ($summaryHeaders as $header): ?><th><?= e($header) ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                    <?php foreach ($summaryRows as $row): ?>
                        <tr><?php foreach ($row as $cell): ?><td><?= e((string) $cell) ?></td><?php endforeach; ?></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </section>
        <?php endif; ?>

        <section class="panel">
            <h2>Records</h2>
            <?php if (!$logRows): ?>
                <p class="muted">No attendance in this period.</p>
            <?php else: ?>
                <div class="table-wrap"><table>
                    <thead><tr><?php foreach ($logHeaders as $header): ?><th><?= e($header) ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                    <?php foreach ($logRows as $row): ?>
                        <tr>
                            <?php foreach ($row as $index => $cell): ?>
                                <td><?= $logHeaders[$index] === 'Status' ? badge(strtolower((string) $cell)) : e((string) $cell) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
            <p class="muted">Think something is wrong? Ask your teacher or the administrator to correct it.</p>
        </section>
    <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
