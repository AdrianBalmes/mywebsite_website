<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';

function config(string $key)
{
    static $config = null;
    $config ??= require APP_ROOT . '/config/config.php';
    return $config[$key];
}

date_default_timezone_set(config('timezone'));

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('session.gc_maxlifetime', (string) config('session_lifetime'));
ini_set('session.use_strict_mode', '1');

session_name('attendance_sid');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

require __DIR__ . '/helpers.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/attendance.php';

set_exception_handler(static function (Throwable $e): void {
    error_log((string) $e);
    show_error(500, 'Something went wrong', 'The request could not be completed. Please try again or contact the administrator.');
});

// Checked here so no page can forget it.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', config('db_host'), config('db_port'), config('db_name'));
        $pdo = new PDO($dsn, config('db_user'), config('db_pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // XAMPP ships in non-strict mode, which silently truncates bad values.
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'");
    }
    return $pdo;
}

function query(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}
