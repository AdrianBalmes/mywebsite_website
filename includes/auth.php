<?php
declare(strict_types=1);

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCK_MINUTES = 15;

// Each tab on the login page and the account roles it accepts.
const LOGIN_PORTALS = [
    'student'  => ['label' => 'Student', 'roles' => ['student'], 'field' => 'ID number'],
    'employee' => ['label' => 'Employee', 'roles' => ['employee', 'teacher'], 'field' => 'ID number or username'],
    'admin'    => ['label' => 'Admin', 'roles' => ['admin', 'kiosk'], 'field' => 'Username'],
];

// A login stops working when the student or employee it belongs to is deactivated.
const ACTIVE_ACCOUNT_SQL = "SELECT u.* FROM users u
    LEFT JOIN students s ON s.id = u.student_id
    LEFT JOIN employees e ON e.id = u.employee_id
    WHERE u.is_active = 1 AND (s.id IS NULL OR s.status = 'active') AND (e.id IS NULL OR e.status = 'active')";

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['user_id'])) {
            // Read on every request so a deactivated account loses access straight away.
            $user = query(ACTIVE_ACCOUNT_SQL . ' AND u.id = ?', [$_SESSION['user_id']])->fetch() ?: null;
        }
    }
    return $user;
}

function require_role(string ...$roles): array
{
    $user = current_user();
    if ($user === null) {
        redirect('index.php');
    }
    if ($roles && !in_array($user['role'], $roles, true)) {
        if (in_array($user['role'], ['kiosk', 'student', 'employee'], true)) {
            redirect(home_page($user));
        }
        show_error(403, 'Access denied', 'Your account does not have access to this page.');
    }
    return $user;
}

function home_page(array $user): string
{
    return match ($user['role']) {
        'kiosk'               => 'kiosk.php',
        'student', 'employee' => 'my_attendance.php',
        default               => 'dashboard.php',
    };
}

function portal_for_role(string $role): string
{
    foreach (LOGIN_PORTALS as $key => $portal) {
        if (in_array($role, $portal['roles'], true)) {
            return $key;
        }
    }
    return 'admin';
}

function login_locked(string $username, string $ip): bool
{
    $since = clock()->modify('-' . LOGIN_LOCK_MINUTES . ' minutes')->format('Y-m-d H:i:s');
    $attempts = query(
        'SELECT COUNT(*) FROM login_attempts WHERE username = ? AND ip_address = ? AND attempted_at >= ?',
        [$username, $ip, $since]
    )->fetchColumn();
    return (int) $attempts >= LOGIN_MAX_ATTEMPTS;
}

function start_session_for(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
}

/** Checks the password only; the login page decides whether this tab may start a session. */
function attempt_login(string $username, string $password, string $ip): ?array
{
    $user = query(ACTIVE_ACCOUNT_SQL . ' AND u.username = ?', [$username])->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        query('DELETE FROM login_attempts WHERE username = ? AND ip_address = ?', [$username, $ip]);
        return $user;
    }
    query(
        'INSERT INTO login_attempts (username, ip_address, attempted_at) VALUES (?, ?, ?)',
        [mb_substr($username, 0, 50), $ip, clock()->format('Y-m-d H:i:s')]
    );
    return null;
}

/** Teachers may only reach classes assigned to them; admins may reach all of them. */
function can_access_class(array $user, array $class): bool
{
    return $user['role'] === 'admin'
        || ($user['role'] === 'teacher' && $user['employee_id'] !== null && (int) $class['teacher_id'] === (int) $user['employee_id']);
}

/** The classes a user may take attendance for and report on. */
function accessible_classes(array $user): array
{
    $sql = 'SELECT c.*, e.first_name, e.last_name FROM classes c LEFT JOIN employees e ON e.id = c.teacher_id';
    if ($user['role'] === 'admin') {
        return query($sql . ' ORDER BY c.status, c.class_code')->fetchAll();
    }
    return query($sql . ' WHERE c.teacher_id = ? ORDER BY c.status, c.class_code', [$user['employee_id'] ?? 0])->fetchAll();
}
