<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);

    if (($_POST['action'] ?? '') === 'toggle') {
        query("UPDATE classes SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?", [$id]);
        log_action('class.toggle_status', "Class #$id");
        flash('success', 'Class status updated.');
        redirect('classes.php');
    }

    $value = static fn (string $key) => trim((string) ($_POST[$key] ?? ''));
    $data = [
        'class_code' => $value('class_code'),
        'subject'    => $value('subject'),
        'section'    => $value('section') ?: null,
        'room'       => $value('room') ?: null,
        'teacher_id' => (int) $value('teacher_id') ?: null,
        'days'       => clean_days((array) ($_POST['days'] ?? [])),
        'start_time' => $value('start_time'),
        'end_time'   => $value('end_time'),
    ];
    $formUrl = $id ? "classes.php?edit=$id" : 'classes.php?new=1';

    $error = match (true) {
        $data['class_code'] === '' || $data['subject'] === '' => 'Class code and subject are required.',
        $data['days'] === '' => 'Choose at least one day.',
        !valid_time($data['start_time']) || !valid_time($data['end_time']) => 'Enter valid start and end times.',
        substr($data['end_time'], 0, 5) <= substr($data['start_time'], 0, 5) => 'The end time must be after the start time.',
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
            query("UPDATE classes SET $sets WHERE id = ?", [...array_values($data), $id]);
            log_action('class.update', $data['class_code']);
        } else {
            $columns = implode(', ', array_keys($data));
            query(
                "INSERT INTO classes ($columns, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [...array_values($data), clock()->format('Y-m-d H:i:s')]
            );
            log_action('class.create', $data['class_code']);
        }
    } catch (PDOException $e) {
        if (!is_duplicate_key($e)) {
            throw $e;
        }
        $_SESSION['form'] = $data;
        flash('error', 'That class code is already in use.');
        redirect($formUrl);
    }
    flash('success', 'Class saved.');
    redirect('classes.php');
}

if (isset($_GET['new']) || isset($_GET['edit'])) {
    $id = (int) ($_GET['edit'] ?? 0);
    $class = $id ? query('SELECT * FROM classes WHERE id = ?', [$id])->fetch() : [];
    if ($id && !$class) {
        show_error(404, 'Not found', 'That class does not exist.');
    }
    $class = ($_SESSION['form'] ?? []) + $class;
    unset($_SESSION['form']);

    $teachers = query("SELECT id, first_name, last_name FROM employees WHERE status = 'active' OR id = ? ORDER BY last_name, first_name", [$class['teacher_id'] ?? 0])->fetchAll();
    $selectedDays = explode(',', $class['days'] ?? '');

    $pageTitle = $id ? 'Edit Class' : 'Add Class';
    require __DIR__ . '/includes/header.php';
    ?>
    <section class="panel">
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <label>Class code <input name="class_code" required maxlength="30" value="<?= e($class['class_code'] ?? '') ?>"></label>
            <label>Subject <input name="subject" required maxlength="120" value="<?= e($class['subject'] ?? '') ?>"></label>
            <label>Section <input name="section" maxlength="30" value="<?= e($class['section'] ?? '') ?>"></label>
            <label>Room <input name="room" maxlength="50" value="<?= e($class['room'] ?? '') ?>"></label>
            <label class="span-2">Teacher
                <select name="teacher_id">
                    <option value="">Unassigned</option>
                    <?php foreach ($teachers as $teacher): ?>
                        <option value="<?= (int) $teacher['id'] ?>"<?= (int) ($class['teacher_id'] ?? 0) === (int) $teacher['id'] ? ' selected' : '' ?>><?= e(full_name($teacher)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <fieldset class="span-2 day-picker">
                <legend>Days</legend>
                <?php foreach (WEEKDAYS as $number => $label): ?>
                    <label class="check"><input type="checkbox" name="days[]" value="<?= $number ?>"<?= in_array((string) $number, $selectedDays, true) ? ' checked' : '' ?>> <?= e($label) ?></label>
                <?php endforeach; ?>
            </fieldset>
            <label>Start time <input type="time" name="start_time" required value="<?= e(substr($class['start_time'] ?? '', 0, 5)) ?>"></label>
            <label>End time <input type="time" name="end_time" required value="<?= e(substr($class['end_time'] ?? '', 0, 5)) ?>"></label>
            <div class="form-actions span-2">
                <button class="btn btn-primary" type="submit">Save</button>
                <a class="btn" href="classes.php">Cancel</a>
            </div>
        </form>
    </section>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$status = in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : 'active';
$classes = query(
    'SELECT c.*, e.first_name, e.last_name, COUNT(cs.student_id) AS enrolled
     FROM classes c
     LEFT JOIN employees e ON e.id = c.teacher_id
     LEFT JOIN class_students cs ON cs.class_id = c.id
     WHERE c.status = ? GROUP BY c.id ORDER BY c.class_code',
    [$status]
)->fetchAll();

$pageTitle = 'Classes';
require __DIR__ . '/includes/header.php';
?>
<form class="filters">
    <label>Status
        <select name="status">
            <option value="active">Active</option>
            <option value="inactive"<?= $status === 'inactive' ? ' selected' : '' ?>>Inactive</option>
        </select>
    </label>
    <button class="btn" type="submit">Filter</button>
    <a class="btn btn-primary push-right" href="classes.php?new=1">Add class</a>
</form>

<section class="panel">
    <?php if (!$classes): ?>
        <p class="muted">No classes found.</p>
    <?php else: ?>
        <div class="table-wrap"><table>
            <thead><tr><th>Code</th><th>Subject</th><th>Teacher</th><th>Schedule</th><th>Room</th><th>Students</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($classes as $class): ?>
                <tr>
                    <td><?= e($class['class_code']) ?></td>
                    <td><?= e($class['subject']) ?></td>
                    <td><?= $class['last_name'] ? e(full_name($class)) : '<span class="muted">Unassigned</span>' ?></td>
                    <td><?= e(fmt_days($class['days'])) ?><br><span class="muted"><?= e(fmt_time($class['start_time'])) ?> - <?= e(fmt_time($class['end_time'])) ?></span></td>
                    <td><?= e($class['room']) ?></td>
                    <td><?= (int) $class['enrolled'] ?></td>
                    <td class="actions">
                        <a class="btn btn-sm" href="class_students.php?class_id=<?= (int) $class['id'] ?>">Students</a>
                        <a class="btn btn-sm" href="classes.php?edit=<?= (int) $class['id'] ?>">Edit</a>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $class['id'] ?>">
                            <button class="btn btn-sm" name="action" value="toggle"><?= $class['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
