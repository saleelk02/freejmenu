<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function promotion_auth_respond($status, $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function promotion_auth_get_env_int($name, $default, $min = 1, $max = 604800) {
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

function promotion_auth_get_admin_username() {
    $value = getenv('MENU_ADMIN_USERNAME');
    if ($value === false || $value === null || trim($value) === '') {
        return 'admin';
    }
    return trim($value);
}

function promotion_auth_get_admin_password() {
    $value = getenv('MENU_ADMIN_PASSWORD');
    if ($value === false || $value === null || $value === '') {
        return 'ChangeMe123!';
    }
    return $value;
}

function promotion_auth_get_username() {
    $value = getenv('MENU_PROMOTION_USERNAME');
    if ($value === false || $value === null || trim($value) === '') {
        return 'promotion';
    }
    return trim($value);
}

function promotion_auth_get_password() {
    $value = getenv('MENU_PROMOTION_PASSWORD');
    if ($value === false || $value === null || $value === '') {
        return 'ChangePromotion123!';
    }
    return $value;
}

function promotion_auth_get_admin_idle_timeout_seconds() {
    return promotion_auth_get_env_int('MENU_ADMIN_IDLE_TIMEOUT', 900, 60, 86400);
}

function promotion_auth_get_admin_session_ttl_seconds() {
    return promotion_auth_get_env_int('MENU_ADMIN_SESSION_TTL', 43200, 300, 604800);
}

function promotion_auth_get_idle_timeout_seconds() {
    return promotion_auth_get_env_int('MENU_PROMOTION_IDLE_TIMEOUT', 900, 60, 86400);
}

function promotion_auth_get_session_ttl_seconds() {
    return promotion_auth_get_env_int('MENU_PROMOTION_SESSION_TTL', 43200, 300, 604800);
}

function promotion_auth_clear_admin_session() {
    unset($_SESSION['menu_admin_authenticated']);
    unset($_SESSION['menu_admin_username']);
    unset($_SESSION['menu_admin_logged_in_at']);
    unset($_SESSION['menu_admin_created_at']);
    unset($_SESSION['menu_admin_last_activity']);
}

function promotion_auth_clear_promotion_session() {
    unset($_SESSION['menu_promotion_authenticated']);
    unset($_SESSION['menu_promotion_username']);
    unset($_SESSION['menu_promotion_logged_in_at']);
    unset($_SESSION['menu_promotion_created_at']);
    unset($_SESSION['menu_promotion_last_activity']);
}

function promotion_auth_is_admin_authenticated() {
    return !empty($_SESSION['menu_admin_authenticated']) && $_SESSION['menu_admin_authenticated'] === true;
}

function promotion_auth_is_promotion_authenticated() {
    return !empty($_SESSION['menu_promotion_authenticated']) && $_SESSION['menu_promotion_authenticated'] === true;
}

function promotion_auth_enforce_admin_timeout() {
    if (!promotion_auth_is_admin_authenticated()) {
        return;
    }

    $now = time();
    $idleTimeout = promotion_auth_get_admin_idle_timeout_seconds();
    $sessionTtl = promotion_auth_get_admin_session_ttl_seconds();
    $lastActivity = isset($_SESSION['menu_admin_last_activity']) ? (int)$_SESSION['menu_admin_last_activity'] : $now;
    $createdAt = isset($_SESSION['menu_admin_created_at']) ? (int)$_SESSION['menu_admin_created_at'] : $now;

    if (($now - $lastActivity) > $idleTimeout || ($now - $createdAt) > $sessionTtl) {
        promotion_auth_clear_admin_session();
        return;
    }

    $_SESSION['menu_admin_last_activity'] = $now;
}

function promotion_auth_enforce_promotion_timeout() {
    if (!promotion_auth_is_promotion_authenticated()) {
        return;
    }

    $now = time();
    $idleTimeout = promotion_auth_get_idle_timeout_seconds();
    $sessionTtl = promotion_auth_get_session_ttl_seconds();
    $lastActivity = isset($_SESSION['menu_promotion_last_activity']) ? (int)$_SESSION['menu_promotion_last_activity'] : $now;
    $createdAt = isset($_SESSION['menu_promotion_created_at']) ? (int)$_SESSION['menu_promotion_created_at'] : $now;

    if (($now - $lastActivity) > $idleTimeout || ($now - $createdAt) > $sessionTtl) {
        promotion_auth_clear_promotion_session();
        return;
    }

    $_SESSION['menu_promotion_last_activity'] = $now;
}

function promotion_auth_start_session() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        promotion_auth_enforce_admin_timeout();
        promotion_auth_enforce_promotion_timeout();
        return;
    }

    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $lifetime = max(
        promotion_auth_get_admin_idle_timeout_seconds(),
        promotion_auth_get_admin_session_ttl_seconds(),
        promotion_auth_get_idle_timeout_seconds(),
        promotion_auth_get_session_ttl_seconds()
    );

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

    promotion_auth_enforce_admin_timeout();
    promotion_auth_enforce_promotion_timeout();
}

function promotion_auth_get_mode() {
    if (promotion_auth_is_admin_authenticated()) {
        return 'admin';
    }
    if (promotion_auth_is_promotion_authenticated()) {
        return 'promotion';
    }
    return null;
}

function promotion_auth_get_username_for_response() {
    if (promotion_auth_is_admin_authenticated()) {
        return isset($_SESSION['menu_admin_username']) ? $_SESSION['menu_admin_username'] : promotion_auth_get_admin_username();
    }

    if (promotion_auth_is_promotion_authenticated()) {
        return isset($_SESSION['menu_promotion_username']) ? $_SESSION['menu_promotion_username'] : promotion_auth_get_username();
    }

    return null;
}

function promotion_auth_get_effective_idle_timeout_seconds() {
    if (promotion_auth_is_admin_authenticated()) {
        return promotion_auth_get_admin_idle_timeout_seconds();
    }
    return promotion_auth_get_idle_timeout_seconds();
}

function promotion_auth_get_effective_session_ttl_seconds() {
    if (promotion_auth_is_admin_authenticated()) {
        return promotion_auth_get_admin_session_ttl_seconds();
    }
    return promotion_auth_get_session_ttl_seconds();
}

function promotion_auth_parse_json_body() {
    $raw = @file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return array();
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        promotion_auth_respond(400, array('error' => 'Invalid JSON body'));
    }

    return $decoded;
}

function promotion_auth_destroy_session() {
    promotion_auth_clear_promotion_session();
    promotion_auth_clear_admin_session();

    if (session_status() === PHP_SESSION_ACTIVE) {
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
}

set_error_handler(function($severity, $message, $file, $line) {
    promotion_auth_respond(500, array(
        'error' => 'PHP runtime error',
        'message' => $message,
        'file' => basename($file),
        'line' => $line
    ));
});

set_exception_handler(function($e) {
    promotion_auth_respond(500, array(
        'error' => 'Unhandled exception',
        'message' => $e->getMessage()
    ));
});

promotion_auth_start_session();

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($method === 'GET') {
    $action = isset($_GET['action']) ? $_GET['action'] : 'status';
    if ($action !== 'status') {
        promotion_auth_respond(400, array('error' => 'Unsupported action'));
    }

    promotion_auth_respond(200, array(
        'authenticated' => promotion_auth_is_admin_authenticated() || promotion_auth_is_promotion_authenticated(),
        'authMode' => promotion_auth_get_mode(),
        'username' => promotion_auth_get_username_for_response(),
        'idleTimeoutSeconds' => promotion_auth_get_effective_idle_timeout_seconds(),
        'sessionTtlSeconds' => promotion_auth_get_effective_session_ttl_seconds()
    ));
}

if ($method !== 'POST') {
    promotion_auth_respond(405, array('error' => 'Method not allowed'));
}

$body = promotion_auth_parse_json_body();
$action = isset($body['action']) ? $body['action'] : (isset($_GET['action']) ? $_GET['action'] : '');

if ($action === 'status') {
    promotion_auth_respond(200, array(
        'authenticated' => promotion_auth_is_admin_authenticated() || promotion_auth_is_promotion_authenticated(),
        'authMode' => promotion_auth_get_mode(),
        'username' => promotion_auth_get_username_for_response(),
        'idleTimeoutSeconds' => promotion_auth_get_effective_idle_timeout_seconds(),
        'sessionTtlSeconds' => promotion_auth_get_effective_session_ttl_seconds()
    ));
}

if ($action === 'login') {
    $username = isset($body['username']) ? trim((string)$body['username']) : '';
    $password = isset($body['password']) ? (string)$body['password'] : '';

    if ($username === '' || $password === '') {
        promotion_auth_respond(400, array('error' => 'Username and password are required'));
    }

    $adminUsername = promotion_auth_get_admin_username();
    $adminPassword = promotion_auth_get_admin_password();
    $promotionUsername = promotion_auth_get_username();
    $promotionPassword = promotion_auth_get_password();

    if (hash_equals($adminUsername, $username) && hash_equals($adminPassword, $password)) {
        session_regenerate_id(true);
        $now = time();

        $_SESSION['menu_admin_authenticated'] = true;
        $_SESSION['menu_admin_username'] = $adminUsername;
        $_SESSION['menu_admin_logged_in_at'] = date('c');
        $_SESSION['menu_admin_created_at'] = $now;
        $_SESSION['menu_admin_last_activity'] = $now;

        promotion_auth_respond(200, array(
            'ok' => true,
            'authenticated' => true,
            'authMode' => 'admin',
            'username' => $adminUsername,
            'message' => 'Admin login successful',
            'idleTimeoutSeconds' => promotion_auth_get_admin_idle_timeout_seconds(),
            'sessionTtlSeconds' => promotion_auth_get_admin_session_ttl_seconds()
        ));
    }

    if (hash_equals($promotionUsername, $username) && hash_equals($promotionPassword, $password)) {
        session_regenerate_id(true);
        $now = time();

        $_SESSION['menu_promotion_authenticated'] = true;
        $_SESSION['menu_promotion_username'] = $promotionUsername;
        $_SESSION['menu_promotion_logged_in_at'] = date('c');
        $_SESSION['menu_promotion_created_at'] = $now;
        $_SESSION['menu_promotion_last_activity'] = $now;

        promotion_auth_respond(200, array(
            'ok' => true,
            'authenticated' => true,
            'authMode' => 'promotion',
            'username' => $promotionUsername,
            'message' => 'Promotion login successful',
            'idleTimeoutSeconds' => promotion_auth_get_idle_timeout_seconds(),
            'sessionTtlSeconds' => promotion_auth_get_session_ttl_seconds()
        ));
    }

    promotion_auth_respond(401, array('error' => 'Invalid username or password'));
}

if ($action === 'logout') {
    promotion_auth_destroy_session();
    promotion_auth_respond(200, array(
        'ok' => true,
        'authenticated' => false,
        'message' => 'Logged out'
    ));
}

promotion_auth_respond(400, array('error' => 'Unsupported action'));
?>
