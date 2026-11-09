<?php
/**
 * helpers.php - small functions used all over the site.
 */
declare(strict_types=1);

/** Escape text before printing it in HTML. Prevents XSS (script injection). */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

// ---- one-time messages ("Record saved") shown after a redirect -----------------
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_take(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

// ---- CSRF protection ------------------------------------------------------------
// Every form carries a secret token that only our own pages know. A malicious
// website cannot guess it, so it cannot make your browser submit a form for you.
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    // hash_equals() instead of ==/=== : it always takes the same amount of time
    // to compare two strings, whatever the input, so an attacker can't use tiny
    // timing differences to guess the token one character at a time.
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(400);
        exit('Invalid form token. Go back, refresh the page and try again.');
    }
}

// ---- reading input safely ---------------------------------------------------------
/** Integer from GET/POST, or null if missing / not a whole number. */
function input_int(array $source, string $key): ?int
{
    if (!isset($source[$key]) || $source[$key] === '') {
        return null;
    }
    $v = filter_var($source[$key], FILTER_VALIDATE_INT);
    return $v === false ? null : (int)$v;
}

/** Decimal number from GET/POST, or null. */
function input_float(array $source, string $key): ?float
{
    if (!isset($source[$key]) || $source[$key] === '') {
        return null;
    }
    $v = filter_var($source[$key], FILTER_VALIDATE_FLOAT);
    return $v === false ? null : (float)$v;
}

function input_str(array $source, string $key, int $max = 100): string
{
    $v = isset($source[$key]) && is_string($source[$key]) ? trim($source[$key]) : '';
    return mb_substr($v, 0, $max);
}

// ---- formatting ---------------------------------------------------------------------
function num($n, int $decimals = 0): string
{
    return $n === null ? '-' : number_format((float)$n, $decimals);
}

function pct($n, int $decimals = 1): string
{
    return $n === null ? '-' : number_format((float)$n, $decimals) . '%';
}

function meal_label(array $m): string
{
    return 'Meal ' . $m['meal_id'] . ' - ' . $m['category'] . ' (' . $m['cuisine'] . ')';
}

function center_label(array $c): string
{
    return 'Kitchen ' . $c['center_id'] . ' (' . $c['center_type'] . ', region ' . $c['region_code'] . ')';
}

/** Build a query string, keeping current filters but changing some. */
function url_with(array $changes): string
{
    $q = array_merge($_GET, $changes);
    foreach ($q as $k => $v) {
        if ($v === null || $v === '') {
            unset($q[$k]);
        }
    }
    return '?' . http_build_query($q);
}

/** Pagination numbers for a list. */
function paginate(int $total, int $perPage, int $page): array
{
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min(max(1, $page), $pages);
    return ['page' => $page, 'pages' => $pages, 'offset' => ($page - 1) * $perPage, 'per_page' => $perPage, 'total' => $total];
}
