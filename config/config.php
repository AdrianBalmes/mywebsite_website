<?php
// Edit these values to match your server, then open install.php once.
return [
    'db_host' => '127.0.0.1',
    'db_port' => 3306,
    'db_name' => 'attendances_system',
    'db_user' => 'root',
    'db_pass' => '',

    // Every attendance time is stamped in this timezone (PHP's XAMPP default is Europe/Berlin).
    'timezone' => 'Asia/Singapore',

    // Long enough that a kiosk left logged in does not drop its session during the day.
    'session_lifetime' => 43200,
];
