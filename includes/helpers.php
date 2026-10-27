<?php
// Shared helpers: secure session, escaping, flash messages, CSRF, login and roles.

const SESSION_IDLE_SECONDS = 1800;   // log out after 30 minutes of no activity

// ---------- Secure session ----------
if (session_status() === PHP_SESSION_NONE) {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('SEMSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Basic security headers
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

// ---------- Output and navigation ----------
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

// ---------- Flash messages ----------
function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_render(): string
{
    if (empty($_SESSION['flash'])) {
        return '';
    }
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $class = ($f['type'] === 'success') ? 'alert-success' : 'alert-error';
    return '<div class="alert ' . $class . '">' . e($f['message']) . '</div>';
}

function errors_render(array $errors): string
{
    if (!$errors) {
        return '';
    }
    $html = '<div class="alert alert-error"><b>Please fix the following:</b><ul style="margin:6px 0 0 18px">';
    foreach ($errors as $err) {
        $html .= '<li>' . e($err) . '</li>';
    }
    return $html . '</ul></div>';
}

// ---------- CSRF ----------
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

// ---------- Login state ----------
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function login_user(array $row): void
{
    session_regenerate_id(true);   // prevents session fixation
    $_SESSION['user'] = [
        'id'          => (int)$row['user_id'],
        'username'    => $row['username'],
        'role'        => $row['role'],
        'lecturer_id' => $row['lecturer_id'],
    ];
    $_SESSION['last_seen'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function logout_user(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

// ---------- Automatic checks on every request ----------
$script      = basename($_SERVER['SCRIPT_NAME'] ?? '');
$publicPages = ['index.php', 'login.php', 'setup_admin.php'];

// 1. Idle timeout
if (!empty($_SESSION['user'])) {
    if (time() - ($_SESSION['last_seen'] ?? 0) > SESSION_IDLE_SECONDS) {
        logout_user();
        flash_set('error', 'Your session expired. Please log in again.');
    } else {
        $_SESSION['last_seen'] = time();
    }
}

// 2. CSRF check on every form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(403);
        exit('Security check failed (missing or invalid form token). Go back, refresh the page and try again.');
    }
}

// 3. Login required for every page except the public ones
if (!in_array($script, $publicPages, true) && empty($_SESSION['user'])) {
    if (empty($_SESSION['flash'])) {
        flash_set('error', 'Please log in to continue.');
    }
    redirect('login.php');
}

// 4. Role check: who may submit forms (change data)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SESSION['user'])) {
    $writeRules = [
        'timetable.php' => ['ADMIN', 'LECTURER'],
        'logout.php'    => ['ADMIN', 'LECTURER', 'VIEWER'],
    ];
    $allowed = $writeRules[$script] ?? ['ADMIN'];
    if (!in_array($_SESSION['user']['role'], $allowed, true)) {
        flash_set('error', 'You do not have permission to change this data.');
        redirect($script);
    }
}