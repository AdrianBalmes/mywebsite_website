<?php
declare(strict_types=1);

// An open time in older than this is treated as a forgotten time out, not as a shift still running.
const MAX_SHIFT_HOURS = 16;
// A second scan this soon is the same person scanning twice, not a time out.
const DUPLICATE_SCAN_SECONDS = 60;

function is_holiday(string $date): bool
{
    static $holidays = null;
    $holidays ??= array_flip(query('SELECT holiday_date FROM holidays')->fetchAll(PDO::FETCH_COLUMN));
    return isset($holidays[$date]);
}

function is_working_day(string $date): bool
{
    $weekday = (new DateTimeImmutable($date))->format('N');
    return in_array($weekday, explode(',', setting('work_days')), true) && !is_holiday($date);
}

function minutes_between(string $from, string $to): int
{
    return intdiv(strtotime($to) - strtotime($from), 60);
}

/** Works out status, lateness, undertime and hours for one employee day. */
function employee_metrics(array $employee, string $workDate, string $timeIn, ?string $timeOut): array
{
    $start = substr($employee['work_start'] ?: setting('work_start'), 0, 5);
    $end = substr($employee['work_end'] ?: setting('work_end'), 0, 5);

    $metrics = ['status' => 'present', 'late_minutes' => 0, 'undertime_minutes' => 0, 'worked_minutes' => 0];
    if ($timeOut !== null) {
        $metrics['worked_minutes'] = max(0, minutes_between($timeIn, $timeOut));
    }
    // Rest days and holidays have no schedule to be late for.
    if (!is_working_day($workDate)) {
        return $metrics;
    }

    $lateBy = minutes_between("$workDate $start:00", $timeIn);
    if ($lateBy > (int) setting('employee_grace_minutes')) {
        $metrics['status'] = 'late';
        $metrics['late_minutes'] = $lateBy;
    }
    if ($timeOut !== null) {
        $shiftEnd = new DateTimeImmutable("$workDate $end:00");
        if ($end <= $start) {
            $shiftEnd = $shiftEnd->modify('+1 day');
        }
        $metrics['undertime_minutes'] = max(0, minutes_between($timeOut, $shiftEnd->format('Y-m-d H:i:s')));
    }
    return $metrics;
}

function scan_result(string $type, string $name, string $message, string $detail = ''): array
{
    return ['type' => $type, 'name' => $name, 'message' => $message, 'detail' => $detail];
}

function recently_scanned(?string $lastScan, DateTimeImmutable $now): bool
{
    return $lastScan !== null && abs($now->getTimestamp() - strtotime($lastScan)) < DUPLICATE_SCAN_SECONDS;
}

/** Handles one ID scan at the time clock: decides who it is and whether this is a time in or time out. */
function record_scan(string $code, ?DateTimeImmutable $now = null): array
{
    $now ??= clock();
    $code = trim($code);
    if ($code === '') {
        return scan_result('error', '', 'Enter or scan an ID number.');
    }

    try {
        $employee = query("SELECT * FROM employees WHERE employee_no = ? AND status = 'active'", [$code])->fetch();
        if ($employee) {
            return employee_scan($employee, $now);
        }
        $student = query("SELECT * FROM students WHERE student_no = ? AND status = 'active'", [$code])->fetch();
        if ($student) {
            return student_scan($student, $now);
        }
    } catch (PDOException $e) {
        // Two kiosks scanned the same person at the same moment: the unique key let one through.
        if (is_duplicate_key($e)) {
            return scan_result('info', '', 'This scan was already recorded.');
        }
        throw $e;
    }
    return scan_result('error', '', 'ID not found or inactive.', $code);
}

function employee_scan(array $employee, DateTimeImmutable $now): array
{
    $name = $employee['first_name'] . ' ' . $employee['last_name'];
    $today = $now->format('Y-m-d');
    $stamp = $now->format('Y-m-d H:i:s');
    $since = $now->modify('-' . MAX_SHIFT_HOURS . ' hours')->format('Y-m-d H:i:s');

    $last = query(
        'SELECT MAX(COALESCE(time_out, time_in)) FROM employee_attendance WHERE employee_id = ? AND work_date >= ?',
        [$employee['id'], $now->modify('-1 day')->format('Y-m-d')]
    )->fetchColumn();
    if (recently_scanned($last ?: null, $now)) {
        return scan_result('info', $name, 'Already scanned just now.', 'Please wait a minute before scanning again.');
    }

    // Includes yesterday's record so an overnight shift can time out after midnight.
    $open = query(
        'SELECT * FROM employee_attendance
         WHERE employee_id = ? AND time_in IS NOT NULL AND time_out IS NULL AND (work_date = ? OR time_in >= ?)
         ORDER BY time_in DESC LIMIT 1',
        [$employee['id'], $today, $since]
    )->fetch();
    if ($open) {
        $metrics = employee_metrics($employee, $open['work_date'], $open['time_in'], $stamp);
        query(
            'UPDATE employee_attendance SET time_out = ?, undertime_minutes = ?, worked_minutes = ? WHERE id = ? AND time_out IS NULL',
            [$stamp, $metrics['undertime_minutes'], $metrics['worked_minutes'], $open['id']]
        );
        return scan_result('out', $name, 'Time out recorded', fmt_minutes($metrics['worked_minutes']) . ' worked');
    }

    $row = query('SELECT * FROM employee_attendance WHERE employee_id = ? AND work_date = ?', [$employee['id'], $today])->fetch();
    if ($row && $row['time_in'] !== null) {
        return scan_result('info', $name, 'Attendance already completed today.', 'Ask an administrator if this needs correcting.');
    }

    $metrics = employee_metrics($employee, $today, $stamp, null);
    if ($row) {
        // Marked absent or on leave beforehand, but the person came in after all.
        query(
            'UPDATE employee_attendance SET time_in = ?, status = ?, late_minutes = ?, updated_by = NULL WHERE id = ?',
            [$stamp, $metrics['status'], $metrics['late_minutes'], $row['id']]
        );
    } else {
        query(
            'INSERT INTO employee_attendance (employee_id, work_date, time_in, status, late_minutes) VALUES (?, ?, ?, ?, ?)',
            [$employee['id'], $today, $stamp, $metrics['status'], $metrics['late_minutes']]
        );
    }
    return scan_result('in', $name, 'Time in recorded', $metrics['status'] === 'late' ? 'Late by ' . fmt_minutes($metrics['late_minutes']) : 'On time');
}

function student_scan(array $student, DateTimeImmutable $now): array
{
    $name = $student['first_name'] . ' ' . $student['last_name'];
    $today = $now->format('Y-m-d');
    $stamp = $now->format('Y-m-d H:i:s');

    $last = query(
        'SELECT MAX(COALESCE(time_out, time_in)) FROM student_attendance WHERE student_id = ? AND session_date = ?',
        [$student['id'], $today]
    )->fetchColumn();
    if (recently_scanned($last ?: null, $now)) {
        return scan_result('info', $name, 'Already scanned just now.', 'Please wait a minute before scanning again.');
    }

    $open = query(
        'SELECT sa.*, c.class_code, c.end_time FROM student_attendance sa
         JOIN classes c ON c.id = sa.class_id
         WHERE sa.student_id = ? AND sa.session_date = ? AND sa.time_in IS NOT NULL AND sa.time_out IS NULL
         ORDER BY sa.time_in DESC LIMIT 1',
        [$student['id'], $today]
    )->fetch();
    if ($open && $stamp <= "$today {$open['end_time']}") {
        return student_time_out($open, $name, $stamp);
    }

    $classes = query(
        "SELECT c.* FROM classes c
         JOIN class_students cs ON cs.class_id = c.id
         WHERE cs.student_id = ? AND c.status = 'active' AND FIND_IN_SET(?, c.days) > 0
         ORDER BY c.start_time",
        [$student['id'], $now->format('N')]
    )->fetchAll();

    $earlyMinutes = (int) setting('class_early_minutes');
    $alreadyDone = null;
    foreach ($classes as $class) {
        $start = new DateTimeImmutable("$today {$class['start_time']}");
        if ($now < $start->modify("-$earlyMinutes minutes") || $stamp > "$today {$class['end_time']}") {
            continue;
        }
        $row = query(
            'SELECT * FROM student_attendance WHERE class_id = ? AND student_id = ? AND session_date = ?',
            [$class['id'], $student['id'], $today]
        )->fetch();
        if ($row && $row['time_in'] !== null) {
            $alreadyDone = $class;
            continue;
        }

        $lateBy = minutes_between($start->format('Y-m-d H:i:s'), $stamp);
        $isLate = $lateBy > (int) setting('class_grace_minutes');
        $status = $isLate ? 'late' : 'present';
        $lateMinutes = $isLate ? $lateBy : 0;
        if ($row) {
            // The teacher marked this student absent before they arrived.
            query(
                'UPDATE student_attendance SET time_in = ?, status = ?, late_minutes = ?, updated_by = NULL WHERE id = ?',
                [$stamp, $status, $lateMinutes, $row['id']]
            );
        } else {
            query(
                'INSERT INTO student_attendance (class_id, student_id, session_date, time_in, status, late_minutes) VALUES (?, ?, ?, ?, ?, ?)',
                [$class['id'], $student['id'], $today, $stamp, $status, $lateMinutes]
            );
        }
        $detail = $class['class_code'] . ' - ' . ($isLate ? 'Late by ' . fmt_minutes($lateMinutes) : 'On time');
        return scan_result('in', $name, 'Time in recorded', $detail);
    }

    // The class ended before the student timed out and no new class has started.
    if ($open) {
        return student_time_out($open, $name, $stamp);
    }
    if ($alreadyDone) {
        return scan_result('info', $name, 'Attendance already completed.', $alreadyDone['class_code']);
    }
    return scan_result('error', $name, 'No class in session right now.', 'Time in opens ' . $earlyMinutes . ' minutes before class.');
}

function student_time_out(array $open, string $name, string $stamp): array
{
    query('UPDATE student_attendance SET time_out = ? WHERE id = ? AND time_out IS NULL', [$stamp, $open['id']]);
    return scan_result('out', $name, 'Time out recorded', $open['class_code']);
}

/** What to show for an employee with no record on a date. */
function expected_status(string $date, ?string $hireDate): string
{
    if (!is_working_day($date) || ($hireDate !== null && $date < $hireDate)) {
        return 'no_schedule';
    }
    return $date < today() ? 'absent' : 'not_yet_in';
}

/** The dates a class meets inside a range, skipping holidays. */
function class_dates(string $days, string $from, string $to): array
{
    $weekdays = explode(',', $days);
    $dates = [];
    $period = new DatePeriod(new DateTimeImmutable($from), new DateInterval('P1D'), (new DateTimeImmutable($to))->modify('+1 day'));
    foreach ($period as $date) {
        if (in_array($date->format('N'), $weekdays, true) && !is_holiday($date->format('Y-m-d'))) {
            $dates[] = $date->format('Y-m-d');
        }
    }
    return $dates;
}

/** The working days inside a range. */
function working_dates(string $from, string $to): array
{
    $dates = [];
    $period = new DatePeriod(new DateTimeImmutable($from), new DateInterval('P1D'), (new DateTimeImmutable($to))->modify('+1 day'));
    foreach ($period as $date) {
        if (is_working_day($date->format('Y-m-d'))) {
            $dates[] = $date->format('Y-m-d');
        }
    }
    return $dates;
}
