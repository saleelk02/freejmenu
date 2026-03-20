<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function respond($status, $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function get_env_int($name, $default, $min = 1, $max = 604800) {
    $raw = getenv($name);
    if ($raw === false || $raw === null || $raw === '') {
        return $default;
    }

    if (!is_numeric($raw)) {
        return $default;
    }

    $value = (int)$raw;
    if ($value < $min) {
        return $min;
    }
    if ($value > $max) {
        return $max;
    }

    return $value;
}

function get_admin_idle_timeout_seconds() {
    return get_env_int('MENU_ADMIN_IDLE_TIMEOUT', 900, 60, 86400);
}

function get_admin_session_ttl_seconds() {
    return get_env_int('MENU_ADMIN_SESSION_TTL', 43200, 300, 604800);
}

function clear_admin_session_auth() {
    unset($_SESSION['menu_admin_authenticated']);
    unset($_SESSION['menu_admin_username']);
    unset($_SESSION['menu_admin_logged_in_at']);
    unset($_SESSION['menu_admin_created_at']);
    unset($_SESSION['menu_admin_last_activity']);
}

function enforce_admin_session_timeout() {
    if (empty($_SESSION['menu_admin_authenticated']) || $_SESSION['menu_admin_authenticated'] !== true) {
        return;
    }

    $now = time();
    $idleTimeout = get_admin_idle_timeout_seconds();
    $sessionTtl = get_admin_session_ttl_seconds();

    $lastActivity = isset($_SESSION['menu_admin_last_activity']) ? (int)$_SESSION['menu_admin_last_activity'] : $now;
    $createdAt = isset($_SESSION['menu_admin_created_at']) ? (int)$_SESSION['menu_admin_created_at'] : $now;

    $idleExpired = ($now - $lastActivity) > $idleTimeout;
    $ttlExpired = ($now - $createdAt) > $sessionTtl;

    if ($idleExpired || $ttlExpired) {
        clear_admin_session_auth();
        return;
    }

    $_SESSION['menu_admin_last_activity'] = $now;
}

function start_admin_session() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        enforce_admin_session_timeout();
        return;
    }

    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $lifetime = max(get_admin_idle_timeout_seconds(), get_admin_session_ttl_seconds());

    @ini_set('session.gc_maxlifetime', (string)$lifetime);
    session_name('FREEJ_ADMIN_SESSID');
    session_set_cookie_params(array(
        'lifetime' => 0,
        'path' => '/menu/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ));
    session_start();
    enforce_admin_session_timeout();
}

function is_admin_authenticated() {
    return !empty($_SESSION['menu_admin_authenticated']) && $_SESSION['menu_admin_authenticated'] === true;
}

function get_admin_username() {
    $value = getenv('MENU_ADMIN_USERNAME');
    if ($value === false || $value === null || trim($value) === '') {
        return 'admin';
    }
    return trim($value);
}

function get_admin_password() {
    $value = getenv('MENU_ADMIN_PASSWORD');
    if ($value === false || $value === null || $value === '') {
        return 'ChangeMe123!';
    }
    return $value;
}

function parse_json_body() {
    $raw = @file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return array();
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        respond(400, array('error' => 'Invalid JSON body'));
    }

    return $decoded;
}

function destroy_admin_session() {
    clear_admin_session_auth();

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', array(
            'expires' => time() - 42000,
            'path' => $params['path'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => 'Lax'
        ));
    }

    session_destroy();
}

set_error_handler(function($severity, $message, $file, $line) {
    respond(500, array(
        'error' => 'PHP runtime error',
        'message' => $message,
        'file' => basename($file),
        'line' => $line
    ));
});

set_exception_handler(function($e) {
    respond(500, array(
        'error' => 'Unhandled exception',
        'message' => $e->getMessage()
    ));
});

start_admin_session();
$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($method === 'GET') {
    $action = isset($_GET['action']) ? $_GET['action'] : 'status';

    if ($action !== 'status') {
        respond(400, array('error' => 'Unsupported action'));
    }

    respond(200, array(
        'authenticated' => is_admin_authenticated(),
        'username' => is_admin_authenticated() ? ($_SESSION['menu_admin_username'] ?? get_admin_username()) : null,
        'idleTimeoutSeconds' => get_admin_idle_timeout_seconds(),
        'sessionTtlSeconds' => get_admin_session_ttl_seconds()
    ));
}

if ($method !== 'POST') {
    respond(405, array('error' => 'Method not allowed'));
}

$body = parse_json_body();
$action = isset($body['action']) ? $body['action'] : (isset($_GET['action']) ? $_GET['action'] : '');

if ($action === 'status') {
    respond(200, array(
        'authenticated' => is_admin_authenticated(),
        'username' => is_admin_authenticated() ? ($_SESSION['menu_admin_username'] ?? get_admin_username()) : null,
        'idleTimeoutSeconds' => get_admin_idle_timeout_seconds(),
        'sessionTtlSeconds' => get_admin_session_ttl_seconds()
    ));
}

if ($action === 'login') {
    $username = isset($body['username']) ? trim((string)$body['username']) : '';
    $password = isset($body['password']) ? (string)$body['password'] : '';

    if ($username === '' || $password === '') {
        respond(400, array('error' => 'Username and password are required'));
    }

    $expectedUsername = get_admin_username();
    $expectedPassword = get_admin_password();

    $validUser = hash_equals($expectedUsername, $username);
    $validPass = hash_equals($expectedPassword, $password);

    if (!$validUser || !$validPass) {
        respond(401, array('error' => 'Invalid username or password'));
    }

    session_regenerate_id(true);
    $now = time();

    $_SESSION['menu_admin_authenticated'] = true;
    $_SESSION['menu_admin_username'] = $expectedUsername;
    $_SESSION['menu_admin_logged_in_at'] = date('c');
    $_SESSION['menu_admin_created_at'] = $now;
    $_SESSION['menu_admin_last_activity'] = $now;

    respond(200, array(
        'ok' => true,
        'authenticated' => true,
        'username' => $expectedUsername,
        'message' => 'Login successful',
        'idleTimeoutSeconds' => get_admin_idle_timeout_seconds(),
        'sessionTtlSeconds' => get_admin_session_ttl_seconds()
    ));
}

if ($action === 'logout') {
    destroy_admin_session();

    respond(200, array(
        'ok' => true,
        'authenticated' => false,
        'message' => 'Logged out'
    ));
}

respond(400, array('error' => 'Unsupported action'));
?>