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

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
start_admin_session();

// Primary path: /menu/data/menu-db.json
$baseDir = dirname(__DIR__);
$dataDir = $baseDir . '/data';
$filePath = $dataDir . '/menu-db.json';

// Fallback path (if /data not writable): /menu/api/menu-db.json
$fallbackPath = __DIR__ . '/menu-db.json';

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (isset($_GET['health']) && $_GET['health'] == '1') {
    $canWriteDataDir = is_dir($dataDir) && is_writable($dataDir);
    $activePath = $filePath;

    if ((!is_dir($dataDir) || !is_writable($dataDir)) && is_writable(__DIR__)) {
        $activePath = $fallbackPath;
    }

    respond(200, array(
        'ok' => true,
        'php' => phpversion(),
        'method' => $method,
        'dataDir' => $dataDir,
        'dataDirExists' => is_dir($dataDir),
        'dataDirWritable' => $canWriteDataDir,
        'apiDirWritable' => is_writable(__DIR__),
        'activePath' => $activePath,
        'activeExists' => file_exists($activePath),
        'authenticated' => is_admin_authenticated(),
        'idleTimeoutSeconds' => get_admin_idle_timeout_seconds(),
        'sessionTtlSeconds' => get_admin_session_ttl_seconds()
    ));
}

// Ensure storage location exists or switch to fallback
$activePath = $filePath;
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0775, true);
}
if (!is_dir($dataDir) || !is_writable($dataDir)) {
    if (is_writable(__DIR__)) {
        $activePath = $fallbackPath;
    } else {
        respond(500, array(
            'error' => 'No writable storage path',
            'details' => 'Make /menu/data writable (775) and menu-db.json writable (664).'
        ));
    }
}

if (!file_exists($activePath)) {
    $seed = json_encode(array('countries' => array(), 'updatedAt' => date('c')), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $created = @file_put_contents($activePath, $seed, LOCK_EX);
    if ($created === false) {
        respond(500, array('error' => 'Failed to create database file', 'path' => $activePath));
    }
}

if ($method === 'GET') {
    $raw = @file_get_contents($activePath);
    if ($raw === false) {
        respond(500, array('error' => 'Failed to read database file', 'path' => $activePath));
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['countries']) || !is_array($decoded['countries'])) {
        $decoded = array('countries' => array(), 'updatedAt' => date('c'));
        $fixed = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        @file_put_contents($activePath, $fixed, LOCK_EX);
    }

    respond(200, $decoded);
}

if ($method === 'POST') {
    if (!is_admin_authenticated()) {
        respond(401, array('error' => 'Unauthorized'));
    }

    $raw = @file_get_contents('php://input');
    $decoded = json_decode($raw, true);

    if (!is_array($decoded) || !isset($decoded['countries']) || !is_array($decoded['countries'])) {
        respond(400, array('error' => 'Invalid payload: countries[] required'));
    }

    if (!isset($decoded['updatedAt'])) {
        $decoded['updatedAt'] = date('c');
    }

    $json = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        respond(400, array('error' => 'Unable to encode payload'));
    }

    $saved = @file_put_contents($activePath, $json, LOCK_EX);
    if ($saved === false) {
        respond(500, array('error' => 'Failed to save database file', 'path' => $activePath));
    }

    respond(200, array('ok' => true, 'path' => $activePath));
}

respond(405, array('error' => 'Method not allowed'));
?>