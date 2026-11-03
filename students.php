<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);

    if (($_POST['action'] ?? '') === 'toggle') {
        query("UPDATE students SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?", [$id]);
        log_action('student.toggle_status', "Student #$id");
        flash('success', 'Student status updated.');
        redirect('students.php');
    }

    $value = static fn (string $key) => trim((string) ($_POST[$key] ?? ''));
    $data = [
        'student_no'     => $value('student_no'),
        'first_name'     => $value('first_name'),
        'last_name'      => $value('last_name'),
        'year_level'     => $value('year_level') ?: null,
        'section'        => $value('section') ?: null,
        'guardian_name'  => $value('guardian_name') ?: null,
        'guardian_phone' => $value('guardian_phone') ?: null,
    ];
    $formUrl = $id ? "students.php?edit=$id" : 'students.php?new=1';

    $error = match (true) {
        $data['student_no'] === '' || $data['first_name'] === '' || $data['last_name'] === '' => 'ID number, first name and last name are required.',
        id_used_by_other_table($data['student_no'], 'employees') => 'That ID number already belongs to an employee.',
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
            query("UPDATE students SET $sets WHERE id = ?", [...array_values($data), $id]);
            log_action('student.update', $data['student_no']);
        } else {
            $columns = implode(', ', array_keys($data));
            query(
                "INSERT INTO students ($columns, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [...array_values($data), clock()->format('Y-m-d H:i:s')]
            );
            log_action('student.create', $data['student_no']);
        }
    } catch (PDOException $e) {
        if (!is_duplicate_key($e)) {
            throw $e;
        }
        $_SESSION['form'] = $data;
        flash('error', 'That ID number is already used by another student.');
        redirect($formUrl);
    }
    flash('success', 'Student saved.');
    redirect('students.php');
}

if (isset($_GET['new']) || isset($_GET['edit'])) {
    $id = (int) ($_GET['edit'] ?? 0);
    $student = $id ? query('SELECT * FROM students WHERE id = ?', [$id])->fetch() : [];
    if ($id && !$student) {
        show_error(404, 'Not found', 'That student does not exist.');
    }
    $student = ($_SESSION['form'] ?? []) + $student;
    unset($_SESSION['form']);

    $pageTitle = $id ? 'Edit Student' : 'Add Student';
    require __DIR__ . '/includes/header.php';
    ?>
    <section class="panel">
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <label class="span-2">ID number <input name="student_no" required maxlength="30" value="<?= e($student['student_no'] ?? '') ?>"></label>
            <label>First name <input name="first_name" required maxlength="60" value="<?= e($student['first_name'] ?? '') ?>"></label>
            <label>Last name <input name="last_name" required maxlength="60" value="<?= e($student['last_name'] ?? '') ?>"></label>
            <label>Year level <input name="year_level" maxlength="30" value="<?= e($student['year_level'] ?? '') ?>"></label>
            <label>Section <input name="section" maxlength="30" value="<?= e($student['section'] ?? '') ?>"></label>
            <label>Guardian name <input name="guardian_name" maxlength="120" value="<?= e($student['guardian_name'] ?? '') ?>"></label>
            <label>Guardian phone <input name="guardian_phone" maxlength="30" value="<?= e($student['guardian_phone'] ?? '') ?>"></label>
            <div class="form-actions span-2">
                <button class="btn btn-primary" type="submit">Save</button>
                <a class="btn" href="students.php">Cancel</a>
            </div>
        </form>
    </section>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$search = trim((string) ($_GET['q'] ?? ''));
$section = trim((string) ($_GET['section'] ?? ''));
$status = in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : 'active';

$where = 'status = ?';
$params = [$status];
if ($search !== '') {
    $where .= ' AND (student_no LIKE ? OR first_name LIKE ? OR last_name LIKE ?)';
    array_push($params, "%$search%", "%$search%", "%$search%");
}
if ($section !== '') {
    $where .= ' AND section = ?';
    $params[] = $section;
}
$total = (int) query("SELECT COUNT(*) FROM students WHERE $where", $params)->fetchColumn();
$students = query(
    "SELECT * FROM students WHERE $where ORDER BY last_name, first_name LIMIT " . PER_PAGE . ' OFFSET ' . page_offset(),
    $params
)->fetchAll();

$pageTitle = 'Students';
require __DIR__ . '/includes/header.php';
?>
<form class="filters">
    <label>Search <input name="q" value="<?= e($search) ?>" placeholder="ID or name"></label>
    <label>Section <input name="section" value="<?= e($section) ?>"></label>
    <label>Status
        <select name="status">
            <option value="active">Active</option>
            <option value="inactive"<?= $status === 'inactive' ? ' selected' : '' ?>>Inactive</option>
        </select>
    </label>
    <button class="btn" type="submit">Filter</button>
    <a class="btn btn-primary push-right" href="students.php?new=1">Add student</a>
</form>

<section class="panel">
    <?php if (!$students): ?>
        <p class="muted">No students found.</p>
    <?php else: ?>
        <div class="table-wrap"><table>
            <thead><tr><th>ID</th><th>Name</th><th>Year level</th><th>Section</th><th>Guardian</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($students as $student): ?>
                <tr>
                    <td><?= e($student['student_no']) ?></td>
                    <td><?= e(full_name($student)) ?></td>
                    <td><?= e($student['year_level']) ?></td>
                    <td><?= e($student['section']) ?></td>
                    <td><?= e($student['guardian_name']) ?> <span class="muted"><?= e($student['guardian_phone']) ?></span></td>
                    <td class="actions">
                        <a class="btn btn-sm" href="students.php?edit=<?= (int) $student['id'] ?>">Edit</a>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $student['id'] ?>">
                            <button class="btn btn-sm" name="action" value="toggle"><?= $student['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
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
