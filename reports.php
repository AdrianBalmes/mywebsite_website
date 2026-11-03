<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/reports.php';

$user = require_role('admin', 'teacher');
$isAdmin = $user['role'] === 'admin';

$reportTypes = $isAdmin
    ? ['employee_daily' => 'Employee daily', 'employee_summary' => 'Employee summary', 'class_summary' => 'Class summary', 'class_log' => 'Class log']
    : ['class_summary' => 'Class summary', 'class_log' => 'Class log'];
$type = (string) ($_GET['type'] ?? array_key_first($reportTypes));
if (!isset($reportTypes[$type])) {
    show_error(403, 'Access denied', 'That report is not available to your account.');
}

$today = today();
$from = valid_date($_GET['from'] ?? null) ? $_GET['from'] : clock()->format('Y-m-01');
$to = valid_date($_GET['to'] ?? null) ? $_GET['to'] : $today;
$date = valid_date($_GET['date'] ?? null) ? $_GET['date'] : $today;
$departmentId = (int) ($_GET['department'] ?? 0);
$classId = (int) ($_GET['class_id'] ?? 0);

$classes = accessible_classes($user);
$class = null;
foreach ($classes as $candidate) {
    if ((int) $candidate['id'] === $classId) {
        $class = $candidate;
    }
}
if ($classId && $class === null) {
    show_error(403, 'Access denied', 'This class is not assigned to you.');
}

$error = '';
if ($from > $to) {
    $error = 'The start date must not be after the end date.';
} elseif ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > MAX_REPORT_DAYS) {
    $error = 'Choose a range of ' . MAX_REPORT_DAYS . ' days or less.';
}

$headers = [];
$rows = [];
$needsClass = in_array($type, ['class_summary', 'class_log'], true);
if ($error === '' && (!$needsClass || $class !== null)) {
    [$headers, $rows] = match ($type) {
        'employee_daily'   => report_employee_daily($date, $departmentId),
        'employee_summary' => report_employee_summary($from, $to, $departmentId),
        'class_summary'    => report_class_summary($class, $from, $to),
        'class_log'        => report_class_log($class, $from, $to),
    };
}

if (($_GET['export'] ?? '') === 'csv' && $headers) {
    export_csv($type . '_' . ($type === 'employee_daily' ? $date : "{$from}_$to") . '.csv', $headers, $rows);
}

$departments = $isAdmin ? query('SELECT id, name FROM departments ORDER BY name')->fetchAll() : [];

$pageTitle = 'Reports';
require __DIR__ . '/includes/header.php';
?>
<nav class="tabs no-print" aria-label="Report type">
    <?php foreach ($reportTypes as $key => $label): ?>
        <a href="reports.php?type=<?= e($key) ?>"<?= $key === $type ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<form class="filters no-print">
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <?php if ($needsClass): ?>
        <label>Class
            <select name="class_id" required>
                <option value="">Choose a class</option>
                <?php foreach ($classes as $option): ?>
                    <option value="<?= (int) $option['id'] ?>"<?= $classId === (int) $option['id'] ? ' selected' : '' ?>><?= e($option['class_code'] . ' - ' . $option['subject']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    <?php else: ?>
        <label>Department
            <select name="department">
                <option value="0">All</option>
                <?php foreach ($departments as $department): ?>
                    <option value="<?= (int) $department['id'] ?>"<?= $departmentId === (int) $department['id'] ? ' selected' : '' ?>><?= e($department['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    <?php endif; ?>
    <?php if ($type === 'employee_daily'): ?>
        <label>Date <input type="date" name="date" value="<?= e($date) ?>"></label>
    <?php else: ?>
        <label>From <input type="date" name="from" value="<?= e($from) ?>"></label>
        <label>To <input type="date" name="to" value="<?= e($to) ?>"></label>
    <?php endif; ?>
    <button class="btn btn-primary" type="submit">Generate</button>
    <?php if ($headers): ?>
        <button class="btn" type="submit" name="export" value="csv">Export CSV</button>
        <button class="btn" type="button" data-print>Print</button>
    <?php endif; ?>
</form>

<?php if ($error): ?>
    <div class="alert alert-error" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<section class="panel">
    <div class="print-only">
        <h2><?= e(setting('school_name')) ?>: <?= e($reportTypes[$type]) ?><?= $class ? ' - ' . e($class['class_code']) : '' ?></h2>
        <p><?= e($type === 'employee_daily' ? fmt_date($date) : fmt_date($from) . ' to ' . fmt_date($to)) ?></p>
    </div>
    <?php if (!$headers): ?>
        <p class="muted"><?= $needsClass && $error === '' ? 'Choose a class to generate this report.' : '' ?></p>
    <?php elseif (!$rows): ?>
        <p class="muted">No data for this selection.</p>
    <?php else: ?>
        <div class="table-wrap"><table>
            <thead><tr><?php foreach ($headers as $header): ?><th><?= e($header) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <?php foreach ($row as $index => $cell): ?>
                        <td><?= $headers[$index] === 'Status' ? badge(strtolower((string) $cell)) : e((string) $cell) ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
