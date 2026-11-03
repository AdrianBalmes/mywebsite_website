<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $name = trim((string) ($_POST['name'] ?? ''));
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'save_settings') {
        $values = [
            'school_name'            => trim((string) ($_POST['school_name'] ?? '')),
            'work_start'             => (string) ($_POST['work_start'] ?? ''),
            'work_end'               => (string) ($_POST['work_end'] ?? ''),
            'work_days'              => clean_days((array) ($_POST['work_days'] ?? [])),
            'employee_grace_minutes' => (string) ($_POST['employee_grace_minutes'] ?? ''),
            'class_grace_minutes'    => (string) ($_POST['class_grace_minutes'] ?? ''),
            'class_early_minutes'    => (string) ($_POST['class_early_minutes'] ?? ''),
        ];
        $isMinutes = static fn (string $value) => ctype_digit($value) && (int) $value <= 240;
        $error = match (true) {
            $values['school_name'] === '' => 'Enter the school name.',
            !valid_time($values['work_start']) || !valid_time($values['work_end']) => 'Enter valid work hours.',
            $values['work_days'] === '' => 'Choose at least one working day.',
            !$isMinutes($values['employee_grace_minutes']), !$isMinutes($values['class_grace_minutes']), !$isMinutes($values['class_early_minutes']) => 'Minutes must be whole numbers from 0 to 240.',
            default => null,
        };
        if ($error !== null) {
            flash('error', $error);
        } else {
            foreach ($values as $key => $value) {
                query('REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)', [$key, $value]);
            }
            log_action('settings.update', json_encode($values));
            flash('success', 'Settings saved.');
        }
    } elseif ($action === 'save_department' && $name !== '') {
        try {
            if ($id) {
                query('UPDATE departments SET name = ? WHERE id = ?', [$name, $id]);
            } else {
                query('INSERT INTO departments (name) VALUES (?)', [$name]);
            }
            log_action('department.save', $name);
            flash('success', 'Department saved.');
        } catch (PDOException $e) {
            if (!is_duplicate_key($e)) {
                throw $e;
            }
            flash('error', 'That department already exists.');
        }
    } elseif ($action === 'delete_department') {
        $inUse = query('SELECT COUNT(*) FROM employees WHERE department_id = ?', [$id])->fetchColumn();
        if ($inUse) {
            flash('error', 'Move the employees out of this department first.');
        } else {
            query('DELETE FROM departments WHERE id = ?', [$id]);
            log_action('department.delete', "Department #$id");
            flash('success', 'Department deleted.');
        }
    } elseif ($action === 'add_holiday') {
        $date = (string) ($_POST['holiday_date'] ?? '');
        if (!valid_date($date) || $name === '') {
            flash('error', 'Enter a holiday date and name.');
        } else {
            query('REPLACE INTO holidays (holiday_date, name) VALUES (?, ?)', [$date, $name]);
            log_action('holiday.save', "$date $name");
            flash('success', 'Holiday saved.');
        }
    } elseif ($action === 'delete_holiday') {
        query('DELETE FROM holidays WHERE holiday_date = ?', [(string) ($_POST['holiday_date'] ?? '')]);
        log_action('holiday.delete', (string) ($_POST['holiday_date'] ?? ''));
        flash('success', 'Holiday deleted.');
    }
    redirect('settings.php');
}

$departments = query('SELECT id, name FROM departments ORDER BY name')->fetchAll();
$holidays = query('SELECT * FROM holidays ORDER BY holiday_date DESC')->fetchAll();
$workDays = explode(',', setting('work_days'));

$pageTitle = 'Settings';
require __DIR__ . '/includes/header.php';
?>
<section class="panel">
    <h2>General</h2>
    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_settings">
        <label class="span-2">School name <input name="school_name" required maxlength="100" value="<?= e(setting('school_name')) ?>"></label>
        <label>Default work start <input type="time" name="work_start" required value="<?= e(setting('work_start')) ?>"></label>
        <label>Default work end <input type="time" name="work_end" required value="<?= e(setting('work_end')) ?>"></label>
        <fieldset class="span-2 day-picker">
            <legend>Working days</legend>
            <?php foreach (WEEKDAYS as $number => $label): ?>
                <label class="check"><input type="checkbox" name="work_days[]" value="<?= $number ?>"<?= in_array((string) $number, $workDays, true) ? ' checked' : '' ?>> <?= e($label) ?></label>
            <?php endforeach; ?>
        </fieldset>
        <label>Employee grace period (minutes) <input type="number" name="employee_grace_minutes" min="0" max="240" required value="<?= e(setting('employee_grace_minutes')) ?>"></label>
        <label>Class grace period (minutes) <input type="number" name="class_grace_minutes" min="0" max="240" required value="<?= e(setting('class_grace_minutes')) ?>"></label>
        <label>Students may time in this many minutes before class <input type="number" name="class_early_minutes" min="0" max="240" required value="<?= e(setting('class_early_minutes')) ?>"></label>
        <div class="form-actions span-2"><button class="btn btn-primary" type="submit">Save settings</button></div>
    </form>
</section>

<div class="columns">
    <section class="panel">
        <h2>Departments</h2>
        <?php foreach ($departments as $department): ?>
            <form method="post" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $department['id'] ?>">
                <input name="name" aria-label="Department name" required maxlength="100" value="<?= e($department['name']) ?>">
                <button class="btn btn-sm" name="action" value="save_department">Save</button>
                <button class="btn btn-sm btn-danger" name="action" value="delete_department" formnovalidate data-confirm="Delete this department?">Delete</button>
            </form>
        <?php endforeach; ?>
        <form method="post" class="inline-form">
            <?= csrf_field() ?>
            <input name="name" aria-label="New department" placeholder="New department" required maxlength="100">
            <button class="btn btn-sm btn-primary" name="action" value="save_department">Add</button>
        </form>
    </section>

    <section class="panel">
        <h2>Holidays</h2>
        <p class="muted">Holidays are not counted as absences for employees or classes.</p>
        <form method="post" class="inline-form">
            <?= csrf_field() ?>
            <input type="date" name="holiday_date" aria-label="Holiday date" required>
            <input name="name" aria-label="Holiday name" placeholder="Holiday name" required maxlength="100">
            <button class="btn btn-sm btn-primary" name="action" value="add_holiday">Add</button>
        </form>
        <?php foreach ($holidays as $holiday): ?>
            <form method="post" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="holiday_date" value="<?= e($holiday['holiday_date']) ?>">
                <span><?= e(fmt_date($holiday['holiday_date'])) ?> <span class="muted"><?= e($holiday['name']) ?></span></span>
                <button class="btn btn-sm btn-danger push-right" name="action" value="delete_holiday" data-confirm="Delete this holiday?">Delete</button>
            </form>
        <?php endforeach; ?>
    </section>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
