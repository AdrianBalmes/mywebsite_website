<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

require_role('admin');

$classId = (int) ($_GET['class_id'] ?? 0);
$class = query('SELECT * FROM classes WHERE id = ?', [$classId])->fetch();
if (!$class) {
    show_error(404, 'Not found', 'That class does not exist.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids = array_map('intval', (array) ($_POST['student_ids'] ?? []));
    if (($_POST['action'] ?? '') === 'remove') {
        foreach ($ids as $id) {
            query('DELETE FROM class_students WHERE class_id = ? AND student_id = ?', [$classId, $id]);
        }
        log_action('class.remove_students', "{$class['class_code']}: " . count($ids) . ' students');
        flash('success', count($ids) . ' students removed. Their past attendance is kept.');
    } else {
        foreach ($ids as $id) {
            query(
                "INSERT IGNORE INTO class_students (class_id, student_id, enrolled_on)
                 SELECT ?, id, ? FROM students WHERE id = ? AND status = 'active'",
                [$classId, today(), $id]
            );
        }
        log_action('class.enrol_students', "{$class['class_code']}: " . count($ids) . ' students');
        flash('success', count($ids) . ' students enrolled.');
    }
    redirect("class_students.php?class_id=$classId");
}

$enrolled = query(
    'SELECT s.id, s.student_no, s.first_name, s.last_name, s.section, cs.enrolled_on
     FROM class_students cs JOIN students s ON s.id = cs.student_id
     WHERE cs.class_id = ? ORDER BY s.last_name, s.first_name',
    [$classId]
)->fetchAll();
$available = query(
    "SELECT s.id, s.student_no, s.first_name, s.last_name, s.section FROM students s
     WHERE s.status = 'active' AND s.id NOT IN (SELECT student_id FROM class_students WHERE class_id = ?)
     ORDER BY s.section, s.last_name, s.first_name",
    [$classId]
)->fetchAll();

$pageTitle = $class['class_code'] . ' Students';
require __DIR__ . '/includes/header.php';
?>
<p class="muted"><?= e($class['subject']) ?> · <a href="classes.php">Back to classes</a></p>

<div class="columns">
    <section class="panel">
        <h2>Enrolled (<?= count($enrolled) ?>)</h2>
        <?php if (!$enrolled): ?>
            <p class="muted">No students enrolled yet.</p>
        <?php else: ?>
            <form method="post" data-pick-list>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="remove">
                <div class="pick-list">
                    <?php foreach ($enrolled as $student): ?>
                        <label class="check" data-pick-item>
                            <input type="checkbox" name="student_ids[]" value="<?= (int) $student['id'] ?>">
                            <?= e(full_name($student)) ?> <span class="muted"><?= e($student['student_no']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="form-actions"><button class="btn btn-danger" type="submit">Remove selected</button></div>
            </form>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Add students</h2>
        <?php if (!$available): ?>
            <p class="muted">Every active student is already enrolled.</p>
        <?php else: ?>
            <form method="post" data-pick-list>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="enrol">
                <div class="toolbar">
                    <input type="search" data-pick-filter aria-label="Filter students" placeholder="Filter by name, ID or section">
                    <button class="btn btn-sm" type="button" data-pick-all>Select shown</button>
                </div>
                <div class="pick-list">
                    <?php foreach ($available as $student): ?>
                        <label class="check" data-pick-item>
                            <input type="checkbox" name="student_ids[]" value="<?= (int) $student['id'] ?>">
                            <?= e(full_name($student)) ?> <span class="muted"><?= e($student['student_no']) ?> <?= e($student['section']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="form-actions"><button class="btn btn-primary" type="submit">Enrol selected</button></div>
            </form>
        <?php endif; ?>
    </section>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
