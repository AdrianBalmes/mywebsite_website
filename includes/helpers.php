<?php
declare(strict_types=1);

const WEEKDAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
const PER_PAGE = 25;

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        show_error(403, 'Page expired', 'This form has expired. Go back, refresh the page and try again.');
    }
}

function show_error(int $code, string $title, string $message): never
{
    http_response_code($code);
    $pageTitle = $title;
    $errorMessage = $message;
    require __DIR__ . '/error_page.php';
    exit;
}

function setting(string $key): string
{
    static $settings = null;
    $settings ??= query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    return $settings[$key] ?? '';
}

function log_action(string $action, string $details = ''): void
{
    query(
        'INSERT INTO audit_log (user_id, action, details, created_at) VALUES (?, ?, ?, ?)',
        [current_user()['id'] ?? null, $action, mb_substr($details, 0, 500), clock()->format('Y-m-d H:i:s')]
    );
}

function clock(): DateTimeImmutable
{
    return new DateTimeImmutable('now');
}

function today(): string
{
    return clock()->format('Y-m-d');
}

function valid_date(mixed $value): bool
{
    if (!is_string($value)) {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function valid_time(?string $value): bool
{
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string) $value) === 1;
}

/** Turns a datetime-local value ("2026-10-31T08:00") into a database datetime, or null if invalid. */
function parse_datetime(?string $value): ?string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', (string) $value);
    return $date ? $date->format('Y-m-d H:i:s') : null;
}

function fmt_date(?string $value): string
{
    return $value ? date('M j, Y', strtotime($value)) : '';
}

function fmt_time(?string $value): string
{
    return $value ? date('g:i A', strtotime($value)) : '';
}

function fmt_datetime(?string $value): string
{
    return $value ? date('M j, g:i A', strtotime($value)) : '';
}

function fmt_minutes(int $minutes): string
{
    return $minutes > 0 ? sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60) : '-';
}

function fmt_days(string $days): string
{
    $labels = [];
    foreach (array_filter(explode(',', $days)) as $day) {
        $labels[] = WEEKDAYS[(int) $day] ?? '';
    }
    return implode(', ', $labels);
}

/** Cleans a submitted weekday list into a sorted CSV of 1-7. */
function clean_days(array $days): string
{
    $days = array_values(array_intersect(array_map('intval', $days), array_keys(WEEKDAYS)));
    sort($days);
    return implode(',', array_unique($days));
}

function full_name(array $person): string
{
    return $person['last_name'] . ', ' . $person['first_name'];
}

function status_label(string $status): string
{
    return ucwords(str_replace('_', ' ', $status));
}

function badge(string $status): string
{
    $class = strtolower(str_replace(' ', '_', $status));
    return '<span class="badge badge-' . e($class) . '">' . e(status_label($status)) . '</span>';
}

/** True when the ID is already used by a person in the other table, so a scan stays unambiguous. */
function id_used_by_other_table(string $code, string $table): bool
{
    $sql = $table === 'employees'
        ? 'SELECT 1 FROM employees WHERE employee_no = ?'
        : 'SELECT 1 FROM students WHERE student_no = ?';
    return (bool) query($sql, [$code])->fetchColumn();
}

function id_number_taken(string $code): bool
{
    return id_used_by_other_table($code, 'employees') || id_used_by_other_table($code, 'students');
}

function is_duplicate_key(PDOException $e): bool
{
    return ($e->errorInfo[1] ?? 0) === 1062;
}

function current_page(): int
{
    return max(1, (int) ($_GET['page'] ?? 1));
}

function page_offset(): int
{
    return (current_page() - 1) * PER_PAGE;
}

function pagination(int $total): string
{
    $pages = (int) ceil($total / PER_PAGE);
    if ($pages <= 1) {
        return '';
    }
    $page = current_page();
    $link = static function (int $target, string $label) {
        $query = http_build_query(['page' => $target] + $_GET);
        return '<a class="btn btn-sm" href="?' . e($query) . '">' . e($label) . '</a>';
    };
    $html = '<nav class="pagination" aria-label="Pages">';
    if ($page > 1) {
        $html .= $link($page - 1, 'Previous');
    }
    $html .= '<span>Page ' . $page . ' of ' . $pages . '</span>';
    if ($page < $pages) {
        $html .= $link($page + 1, 'Next');
    }
    return $html . '</nav>';
}

/** Streams a CSV download. Cells starting with a formula character are quoted so Excel will not run them. */
function export_csv(string $filename, array $headers, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, array_map(
            static fn ($cell) => preg_match('/^[=+\-@\t\r]/', (string) $cell) && $cell !== '-' ? "'" . $cell : $cell,
            $row
        ));
    }
    fclose($out);
    exit;
}
