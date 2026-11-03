<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

require_role('admin');

$total = (int) query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
$entries = query(
    'SELECT a.*, u.username FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
     ORDER BY a.id DESC LIMIT ' . PER_PAGE . ' OFFSET ' . page_offset()
)->fetchAll();

$pageTitle = 'Audit Log';
require __DIR__ . '/includes/header.php';
?>
<section class="panel">
    <?php if (!$entries): ?>
        <p class="muted">No changes have been recorded yet.</p>
    <?php else: ?>
        <div class="table-wrap"><table>
            <thead><tr><th>When</th><th>User</th><th>Action</th><th>Details</th></tr></thead>
            <tbody>
            <?php foreach ($entries as $entry): ?>
                <tr>
                    <td><?= e(fmt_date($entry['created_at'])) ?> <?= e(fmt_time($entry['created_at'])) ?></td>
                    <td><?= e($entry['username']) ?></td>
                    <td><?= e($entry['action']) ?></td>
                    <td><?= e($entry['details']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination($total) ?>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
