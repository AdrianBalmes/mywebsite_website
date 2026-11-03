<?php
declare(strict_types=1);

const MAX_REPORT_DAYS = 366;

/** Every report returns [headers, rows] so the page and the CSV export share one code path. */
function report_employee_daily(string $date, int $departmentId): array
{
    $params = [$date];
    $departmentSql = '';
    if ($departmentId) {
        $departmentSql = ' AND e.department_id = ?';
        $params[] = $departmentId;
    }
    $records = query(
        "SELECT e.employee_no, e.first_name, e.last_name, e.hire_date, d.name AS department, a.time_in, a.time_out, a.status,
                a.late_minutes, a.undertime_minutes, a.worked_minutes, a.remarks
         FROM employees e
         LEFT JOIN departments d ON d.id = e.department_id
         LEFT JOIN employee_attendance a ON a.employee_id = e.id AND a.work_date = ?
         WHERE (e.status = 'active' OR a.id IS NOT NULL)$departmentSql
         ORDER BY d.name, e.last_name, e.first_name",
        $params
    )->fetchAll();

    $rows = [];
    foreach ($records as $record) {
        $status = $record['status'] ?? expected_status($date, $record['hire_date']);
        $rows[] = [
            $record['employee_no'], full_name($record), (string) $record['department'], fmt_time($record['time_in']), fmt_time($record['time_out']),
            status_label($status), fmt_minutes((int) $record['late_minutes']), fmt_minutes((int) $record['undertime_minutes']),
            fmt_minutes((int) $record['worked_minutes']), (string) $record['remarks'],
        ];
    }
    return [['ID', 'Name', 'Department', 'Time in', 'Time out', 'Status', 'Late', 'Undertime', 'Worked', 'Remarks'], $rows];
}

/** Working days that have ended, so a missing record there is a real absence. */
function past_working_days(string $from, string $to): array
{
    // Today is left out: the shift may not be over, so a missing record is not an absence yet.
    $lastDay = min($to, clock()->modify('-1 day')->format('Y-m-d'));
    return $from <= $lastDay ? working_dates($from, $lastDay) : [];
}

function missing_employee_days(array $workDays, array $recorded, ?string $hireDate): array
{
    return array_values(array_filter(
        $workDays,
        static fn (string $day) => !isset($recorded[$day]) && ($hireDate === null || $day >= $hireDate)
    ));
}

function report_employee_summary(string $from, string $to, int $departmentId, ?int $employeeId = null): array
{
    $params = [$from, $to, $from, $to];
    $departmentSql = '';
    if ($departmentId) {
        $departmentSql = ' AND e.department_id = ?';
        $params[] = $departmentId;
    }
    if ($employeeId !== null) {
        $departmentSql .= ' AND e.id = ?';
        $params[] = $employeeId;
    }
    $employees = query(
        "SELECT e.id, e.employee_no, e.first_name, e.last_name, e.hire_date, e.status AS employee_status, d.name AS department,
                COUNT(CASE WHEN a.status = 'present' THEN 1 END) AS present,
                COUNT(CASE WHEN a.status = 'late' THEN 1 END) AS late,
                COUNT(CASE WHEN a.status = 'absent' THEN 1 END) AS marked_absent,
                COUNT(CASE WHEN a.status = 'on_leave' THEN 1 END) AS on_leave,
                COALESCE(SUM(a.late_minutes), 0) AS late_minutes,
                COALESCE(SUM(a.undertime_minutes), 0) AS undertime_minutes,
                COALESCE(SUM(a.worked_minutes), 0) AS worked_minutes
         FROM employees e
         LEFT JOIN departments d ON d.id = e.department_id
         LEFT JOIN employee_attendance a ON a.employee_id = e.id AND a.work_date BETWEEN ? AND ?
         WHERE (e.status = 'active' OR EXISTS (SELECT 1 FROM employee_attendance x WHERE x.employee_id = e.id AND x.work_date BETWEEN ? AND ?))$departmentSql
         GROUP BY e.id ORDER BY d.name, e.last_name, e.first_name",
        $params
    )->fetchAll();

    $workDays = past_working_days($from, $to);

    $recorded = [];
    $records = query('SELECT employee_id, work_date FROM employee_attendance WHERE work_date BETWEEN ? AND ?', [$from, $to])->fetchAll();
    foreach ($records as $record) {
        $recorded[$record['employee_id']][$record['work_date']] = true;
    }

    $rows = [];
    foreach ($employees as $employee) {
        $missing = 0;
        // An inactive employee has no end date on file, so only recorded absences count for them.
        if ($employee['employee_status'] === 'active') {
            $missing = count(missing_employee_days($workDays, $recorded[$employee['id']] ?? [], $employee['hire_date']));
        }
        $rows[] = [
            $employee['employee_no'], full_name($employee), (string) $employee['department'],
            (int) $employee['present'] + (int) $employee['late'], (int) $employee['late'], (int) $employee['marked_absent'] + $missing, (int) $employee['on_leave'],
            fmt_minutes((int) $employee['late_minutes']), fmt_minutes((int) $employee['undertime_minutes']), fmt_minutes((int) $employee['worked_minutes']),
        ];
    }
    return [['ID', 'Name', 'Department', 'Days attended', 'Days late', 'Absent', 'On leave', 'Total late', 'Total undertime', 'Total worked'], $rows];
}

/** Class sessions that have ended, so a missing record there is a real absence. */
function past_class_sessions(array $class, string $from, string $to): array
{
    if ($class['status'] !== 'active') {
        return [];
    }
    // Today only counts once the class has ended, otherwise students not yet in would show as absent.
    $now = clock();
    $lastDay = $now->format('H:i:s') > $class['end_time'] ? $now->format('Y-m-d') : $now->modify('-1 day')->format('Y-m-d');
    $lastDay = min($to, $lastDay);
    return $from <= $lastDay ? class_dates($class['days'], $from, $lastDay) : [];
}

/** Totals one student's marks in a class. Returns the counts and the sessions with no record. */
function tally_student_marks(array $marks, array $sessions, ?string $enrolledOn): array
{
    $counts = ['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0];
    foreach ($marks as $status) {
        $counts[$status]++;
    }
    $missing = [];
    if ($enrolledOn !== null) {
        foreach ($sessions as $session) {
            // Sessions start the day after enrolment: that day's class may have ended before they joined.
            if (!isset($marks[$session]) && $session > $enrolledOn) {
                $missing[] = $session;
                $counts['absent']++;
            }
        }
    }
    return [$counts, $missing];
}

function attendance_rate(array $counts): string
{
    $attended = $counts['present'] + $counts['late'];
    $held = $attended + $counts['absent'];
    return $held > 0 ? round($attended / $held * 100) . '%' : '-';
}

function report_class_summary(array $class, string $from, string $to): array
{
    $students = query(
        "SELECT s.id, s.student_no, s.first_name, s.last_name, s.status, cs.enrolled_on
         FROM students s
         LEFT JOIN class_students cs ON cs.student_id = s.id AND cs.class_id = ?
         WHERE (cs.class_id IS NOT NULL AND s.status = 'active')
            OR EXISTS (SELECT 1 FROM student_attendance x WHERE x.student_id = s.id AND x.class_id = ? AND x.session_date BETWEEN ? AND ?)
         ORDER BY s.last_name, s.first_name",
        [$class['id'], $class['id'], $from, $to]
    )->fetchAll();

    $marks = [];
    $records = query(
        'SELECT student_id, session_date, status FROM student_attendance WHERE class_id = ? AND session_date BETWEEN ? AND ?',
        [$class['id'], $from, $to]
    )->fetchAll();
    foreach ($records as $record) {
        $marks[$record['student_id']][$record['session_date']] = $record['status'];
    }

    $sessions = past_class_sessions($class, $from, $to);

    $rows = [];
    foreach ($students as $student) {
        // Like employees, a student who has left or been removed only keeps the marks on record.
        $enrolledOn = $student['status'] === 'active' ? $student['enrolled_on'] : null;
        [$counts] = tally_student_marks($marks[$student['id']] ?? [], $sessions, $enrolledOn);
        $rows[] = [
            $student['student_no'], full_name($student), $counts['present'], $counts['late'], $counts['absent'], $counts['excused'],
            attendance_rate($counts),
        ];
    }
    return [['ID', 'Name', 'Present', 'Late', 'Absent', 'Excused', 'Attendance rate'], $rows];
}

/** The classes a student is enrolled in, with today's mark for each. */
function student_subjects(int $studentId): array
{
    return query(
        "SELECT c.class_code, c.subject, c.section, c.room, c.days, c.start_time, c.end_time, e.first_name, e.last_name, a.status AS today_status,
                a.time_in AS today_in, a.time_out AS today_out,
                FIND_IN_SET(?, c.days) > 0 AND c.status = 'active' AS meets_today
         FROM class_students cs
         JOIN classes c ON c.id = cs.class_id
         LEFT JOIN employees e ON e.id = c.teacher_id
         LEFT JOIN student_attendance a ON a.class_id = c.id AND a.student_id = cs.student_id AND a.session_date = ?
         WHERE cs.student_id = ?
         ORDER BY c.start_time, c.class_code",
        [clock()->format('N'), today(), $studentId]
    )->fetchAll();
}

/** One student's own record: [summary headers, summary rows, log headers, log rows]. */
function student_attendance_record(int $studentId, string $from, string $to): array
{
    $classes = query(
        'SELECT c.*, cs.enrolled_on FROM classes c
         LEFT JOIN class_students cs ON cs.class_id = c.id AND cs.student_id = ?
         WHERE cs.class_id IS NOT NULL
            OR EXISTS (SELECT 1 FROM student_attendance x WHERE x.class_id = c.id AND x.student_id = ? AND x.session_date BETWEEN ? AND ?)
         ORDER BY c.class_code',
        [$studentId, $studentId, $from, $to]
    )->fetchAll();

    $records = query(
        'SELECT class_id, session_date, time_in, time_out, status, remarks FROM student_attendance
         WHERE student_id = ? AND session_date BETWEEN ? AND ?',
        [$studentId, $from, $to]
    )->fetchAll();
    $marks = [];
    foreach ($records as $record) {
        $marks[$record['class_id']][$record['session_date']] = $record['status'];
    }

    $codes = array_column($classes, 'class_code', 'id');
    $summary = [];
    $log = [];
    foreach ($records as $record) {
        $log[] = [$record['session_date'], $codes[$record['class_id']], $record['time_in'], $record['time_out'], $record['status'], (string) $record['remarks']];
    }
    foreach ($classes as $class) {
        $sessions = past_class_sessions($class, $from, $to);
        [$counts, $missing] = tally_student_marks($marks[$class['id']] ?? [], $sessions, $class['enrolled_on']);
        $summary[] = [$class['class_code'], $class['subject'], $counts['present'], $counts['late'], $counts['absent'], $counts['excused'], attendance_rate($counts)];
        foreach ($missing as $session) {
            $log[] = [$session, $class['class_code'], null, null, 'absent', ''];
        }
    }

    usort($log, static fn (array $a, array $b) => [$b[0], $a[1]] <=> [$a[0], $b[1]]);
    $rows = array_map(
        static fn (array $row) => [fmt_date($row[0]), $row[1], fmt_time($row[2]), fmt_time($row[3]), status_label($row[4]), $row[5]],
        $log
    );
    return [
        ['Class', 'Subject', 'Present', 'Late', 'Absent', 'Excused', 'Attendance rate'], $summary,
        ['Date', 'Class', 'Time in', 'Time out', 'Status', 'Remarks'], $rows,
    ];
}

/** Today's record, or last night's shift if it is still running. Same rule as the time clock. */
function employee_current_record(int $employeeId): ?array
{
    $since = clock()->modify('-' . MAX_SHIFT_HOURS . ' hours')->format('Y-m-d H:i:s');
    return query(
        'SELECT * FROM employee_attendance
         WHERE employee_id = ? AND (work_date = ? OR (time_in >= ? AND time_out IS NULL))
         ORDER BY work_date DESC LIMIT 1',
        [$employeeId, today(), $since]
    )->fetch() ?: null;
}

/** One employee's own record: [summary headers, summary rows, log headers, log rows]. */
function employee_attendance_record(array $employee, string $from, string $to): array
{
    [$headers, $rows] = report_employee_summary($from, $to, 0, (int) $employee['id']);
    // The first three columns repeat the employee's own ID, name and department.
    $summaryHeaders = array_slice($headers, 3);
    $summary = array_map(static fn (array $row) => array_slice($row, 3), $rows);

    $records = query(
        'SELECT work_date, time_in, time_out, status, late_minutes, undertime_minutes, worked_minutes, remarks
         FROM employee_attendance WHERE employee_id = ? AND work_date BETWEEN ? AND ?',
        [$employee['id'], $from, $to]
    )->fetchAll();
    $log = array_column($records, null, 'work_date');
    foreach (missing_employee_days(past_working_days($from, $to), $log, $employee['hire_date']) as $day) {
        $log[$day] = ['work_date' => $day, 'time_in' => null, 'time_out' => null, 'status' => 'absent', 'late_minutes' => 0, 'undertime_minutes' => 0, 'worked_minutes' => 0, 'remarks' => ''];
    }
    krsort($log);

    $logRows = [];
    foreach ($log as $record) {
        $logRows[] = [
            fmt_date($record['work_date']), fmt_datetime($record['time_in']), fmt_datetime($record['time_out']), status_label($record['status']),
            fmt_minutes((int) $record['late_minutes']), fmt_minutes((int) $record['undertime_minutes']), fmt_minutes((int) $record['worked_minutes']), (string) $record['remarks'],
        ];
    }
    return [$summaryHeaders, $summary, ['Date', 'Time in', 'Time out', 'Status', 'Late', 'Undertime', 'Worked', 'Remarks'], $logRows];
}

function report_class_log(array $class, string $from, string $to): array
{
    $records = query(
        'SELECT a.session_date, s.student_no, s.first_name, s.last_name, a.time_in, a.time_out, a.status, a.late_minutes, a.remarks
         FROM student_attendance a JOIN students s ON s.id = a.student_id
         WHERE a.class_id = ? AND a.session_date BETWEEN ? AND ?
         ORDER BY a.session_date DESC, s.last_name, s.first_name',
        [$class['id'], $from, $to]
    )->fetchAll();

    $rows = [];
    foreach ($records as $record) {
        $rows[] = [
            fmt_date($record['session_date']), $record['student_no'], full_name($record), fmt_time($record['time_in']), fmt_time($record['time_out']),
            status_label($record['status']), fmt_minutes((int) $record['late_minutes']), (string) $record['remarks'],
        ];
    }
    return [['Date', 'ID', 'Name', 'Time in', 'Time out', 'Status', 'Late', 'Remarks'], $rows];
}
