<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_regenerate_id(true);
    $_SESSION = [];
}
redirect('index.php');
