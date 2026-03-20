<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function promotion_upload_respond($status, $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function promotion_upload_get_env_int($name, $default, $min = 1, $max = 604800) {
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

function promotion_upload_get_admin_idle_timeout_seconds() {
    return promotion_upload_get_env_int('MENU_ADMIN_IDLE_TIMEOUT', 900, 60, 86400);
}

function promotion_upload_get_admin_session_ttl_seconds() {
    return promotion_upload_get_env_int('MENU_ADMIN_SESSION_TTL', 43200, 300, 604800);
}

function promotion_upload_get_idle_timeout_seconds() {
    return promotion_upload_get_env_int('MENU_PROMOTION_IDLE_TIMEOUT', 900, 60, 86400);
}

function promotion_upload_get_session_ttl_seconds() {
    return promotion_upload_get_env_int('MENU_PROMOTION_SESSION_TTL', 43200, 300, 604800);
}

function promotion_upload_clear_admin_session() {
    unset($_SESSION['menu_admin_authenticated']);
    unset($_SESSION['menu_admin_username']);
    unset($_SESSION['menu_admin_logged_in_at']);
    unset($_SESSION['menu_admin_created_at']);
    unset($_SESSION['menu_admin_last_activity']);
}

function promotion_upload_clear_promotion_session() {
    unset($_SESSION['menu_promotion_authenticated']);
    unset($_SESSION['menu_promotion_username']);
    unset($_SESSION['menu_promotion_logged_in_at']);
    unset($_SESSION['menu_promotion_created_at']);
    unset($_SESSION['menu_promotion_last_activity']);
}

function promotion_upload_is_admin_authenticated() {
    return !empty($_SESSION['menu_admin_authenticated']) && $_SESSION['menu_admin_authenticated'] === true;
}

function promotion_upload_is_promotion_authenticated() {
    return !empty($_SESSION['menu_promotion_authenticated']) && $_SESSION['menu_promotion_authenticated'] === true;
}

function promotion_upload_has_manager_access() {
    return promotion_upload_is_admin_authenticated() || promotion_upload_is_promotion_authenticated();
}

function promotion_upload_enforce_admin_timeout() {
    if (!promotion_upload_is_admin_authenticated()) {
        return;
    }

    $now = time();
    $idleTimeout = promotion_upload_get_admin_idle_timeout_seconds();
    $sessionTtl = promotion_upload_get_admin_session_ttl_seconds();
    $lastActivity = isset($_SESSION['menu_admin_last_activity']) ? (int)$_SESSION['menu_admin_last_activity'] : $now;
    $createdAt = isset($_SESSION['menu_admin_created_at']) ? (int)$_SESSION['menu_admin_created_at'] : $now;

    if (($now - $lastActivity) > $idleTimeout || ($now - $createdAt) > $sessionTtl) {
        promotion_upload_clear_admin_session();
        return;
    }

    $_SESSION['menu_admin_last_activity'] = $now;
}

function promotion_upload_enforce_promotion_timeout() {
    if (!promotion_upload_is_promotion_authenticated()) {
        return;
    }

    $now = time();
    $idleTimeout = promotion_upload_get_idle_timeout_seconds();
    $sessionTtl = promotion_upload_get_session_ttl_seconds();
    $lastActivity = isset($_SESSION['menu_promotion_last_activity']) ? (int)$_SESSION['menu_promotion_last_activity'] : $now;
    $createdAt = isset($_SESSION['menu_promotion_created_at']) ? (int)$_SESSION['menu_promotion_created_at'] : $now;

    if (($now - $lastActivity) > $idleTimeout || ($now - $createdAt) > $sessionTtl) {
        promotion_upload_clear_promotion_session();
        return;
    }

    $_SESSION['menu_promotion_last_activity'] = $now;
}

function promotion_upload_start_session() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        promotion_upload_enforce_admin_timeout();
        promotion_upload_enforce_promotion_timeout();
        return;
    }

    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $lifetime = max(
        promotion_upload_get_admin_idle_timeout_seconds(),
        promotion_upload_get_admin_session_ttl_seconds(),
        promotion_upload_get_idle_timeout_seconds(),
        promotion_upload_get_session_ttl_seconds()
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

    promotion_upload_enforce_admin_timeout();
    promotion_upload_enforce_promotion_timeout();
}

function promotion_upload_get_storage_path() {
    $baseDir = dirname(__DIR__);
    $dataDir = $baseDir . '/data';
    $primaryPath = $dataDir . '/menu-db.json';
    $fallbackPath = __DIR__ . '/menu-db.json';

    if (!is_dir($dataDir)) {
        @mkdir($dataDir, 0775, true);
    }

    if (is_dir($dataDir) && is_writable($dataDir)) {
        return $primaryPath;
    }

    if (is_writable(__DIR__)) {
        return $fallbackPath;
    }

    promotion_upload_respond(500, array(
        'error' => 'No writable storage path',
        'details' => 'Make /menu/data writable (775) and menu-db.json writable (664).'
    ));
}

function promotion_upload_ensure_storage_file($path) {
    if (file_exists($path)) {
        return;
    }

    $seed = json_encode(array('countries' => array(), 'updatedAt' => date('c'), 'promotionHistory' => array()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $created = @file_put_contents($path, $seed, LOCK_EX);
    if ($created === false) {
        promotion_upload_respond(500, array('error' => 'Failed to create database file', 'path' => $path));
    }
}

function promotion_upload_read_db() {
    $path = promotion_upload_get_storage_path();
    promotion_upload_ensure_storage_file($path);

    $raw = @file_get_contents($path);
    if ($raw === false) {
        promotion_upload_respond(500, array('error' => 'Failed to read database file', 'path' => $path));
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['countries']) || !is_array($decoded['countries'])) {
        $decoded = array('countries' => array(), 'updatedAt' => date('c'), 'promotionHistory' => array());
    }

    if (!isset($decoded['promotionHistory']) || !is_array($decoded['promotionHistory'])) {
        $decoded['promotionHistory'] = array();
    }

    return array($decoded, $path);
}

function promotion_upload_write_db($db) {
    list($_current, $path) = promotion_upload_read_db();

    if (!is_array($db) || !isset($db['countries']) || !is_array($db['countries'])) {
        promotion_upload_respond(400, array('error' => 'Invalid database payload'));
    }

    if (!isset($db['promotionHistory']) || !is_array($db['promotionHistory'])) {
        $db['promotionHistory'] = array();
    }

    $db['updatedAt'] = date('c');
    $json = json_encode($db, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        promotion_upload_respond(500, array('error' => 'Failed to encode database payload'));
    }

    $saved = @file_put_contents($path, $json, LOCK_EX);
    if ($saved === false) {
        promotion_upload_respond(500, array('error' => 'Failed to save database file', 'path' => $path));
    }

    return array($db, $path);
}

function promotion_upload_get_upload_dir() {
    return dirname(__DIR__) . '/uploads/promotions';
}

function promotion_upload_get_single_upload_limit_bytes() {
    return 1024 * 1024;
}

function promotion_upload_get_total_upload_limit_bytes() {
    return 10 * 1024 * 1024 * 1024;
}

function promotion_upload_get_usage_bytes() {
    $uploadDir = promotion_upload_get_upload_dir();
    if (!is_dir($uploadDir)) {
        return 0;
    }

    $total = 0;
    $items = @scandir($uploadDir);
    if (!is_array($items)) {
        return 0;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $uploadDir . '/' . $item;
        if (is_file($path)) {
            $size = @filesize($path);
            if ($size !== false) {
                $total += (int)$size;
            }
        }
    }

    return $total;
}

function promotion_upload_get_country_name($country) {
    if (!is_array($country)) {
        return 'Country';
    }

    if (isset($country['name']) && is_array($country['name'])) {
        if (!empty($country['name']['en'])) {
            return (string)$country['name']['en'];
        }
        if (!empty($country['name']['ar'])) {
            return (string)$country['name']['ar'];
        }
    }

    if (!empty($country['slug'])) {
        return (string)$country['slug'];
    }

    return 'Country';
}

function promotion_upload_build_history_entry($action, $country, $promotion) {
    $image = isset($promotion['image']) ? (string)$promotion['image'] : '';
    $imageFilename = isset($promotion['imageFilename']) && $promotion['imageFilename'] !== ''
        ? (string)$promotion['imageFilename']
        : basename(parse_url($image, PHP_URL_PATH) ?: '');

    return array(
        'id' => 'promotion-history-' . time() . '-' . substr(md5(uniqid('', true)), 0, 8),
        'action' => (string)$action,
        'countryId' => isset($country['id']) ? (string)$country['id'] : '',
        'countryName' => promotion_upload_get_country_name($country),
        'countrySlug' => isset($country['slug']) ? (string)$country['slug'] : '',
        'promotionId' => isset($promotion['id']) ? (string)$promotion['id'] : '',
        'promotionName' => isset($promotion['name']) ? (string)$promotion['name'] : 'Promotion',
        'image' => $image,
        'imageFilename' => $imageFilename,
        'imageSizeBytes' => max(0, isset($promotion['imageSizeBytes']) ? (int)$promotion['imageSizeBytes'] : 0),
        'uploadedAt' => isset($promotion['uploadedAt']) ? (string)$promotion['uploadedAt'] : '',
        'startAt' => isset($promotion['startAt']) ? (string)$promotion['startAt'] : '',
        'endAt' => isset($promotion['endAt']) ? (string)$promotion['endAt'] : '',
        'displaySeconds' => isset($promotion['displaySeconds']) ? (int)$promotion['displaySeconds'] : 5,
        'enabled' => !isset($promotion['enabled']) || $promotion['enabled'] !== false,
        'createdAt' => date('c')
    );
}

function promotion_upload_append_history_entries(&$db, $entries) {
    if (!isset($db['promotionHistory']) || !is_array($db['promotionHistory'])) {
        $db['promotionHistory'] = array();
    }

    foreach ($entries as $entry) {
        if (is_array($entry)) {
            $db['promotionHistory'][] = $entry;
        }
    }

    if (count($db['promotionHistory']) > 2000) {
        $db['promotionHistory'] = array_slice($db['promotionHistory'], -2000);
    }
}

set_error_handler(function($severity, $message, $file, $line) {
    promotion_upload_respond(500, array(
        'error' => 'PHP runtime error',
        'message' => $message,
        'file' => basename($file),
        'line' => $line
    ));
});

set_exception_handler(function($e) {
    promotion_upload_respond(500, array(
        'error' => 'Unhandled exception',
        'message' => $e->getMessage()
    ));
});

promotion_upload_start_session();

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!promotion_upload_has_manager_access()) {
    promotion_upload_respond(401, array('error' => 'Unauthorized'));
}

if ($method === 'GET') {
    $totalBytes = promotion_upload_get_usage_bytes();
    $limitBytes = promotion_upload_get_total_upload_limit_bytes();

    promotion_upload_respond(200, array(
        'ok' => true,
        'totalBytes' => $totalBytes,
        'limitBytes' => $limitBytes,
        'remainingBytes' => max(0, $limitBytes - $totalBytes)
    ));
}

if ($method !== 'POST') {
    promotion_upload_respond(405, array('error' => 'Method not allowed'));
}

if (!isset($_FILES['file'])) {
    promotion_upload_respond(400, array('error' => 'No file uploaded'));
}

$file = $_FILES['file'];
if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
    promotion_upload_respond(400, array('error' => 'Image upload failed'));
}

$maxBytes = promotion_upload_get_single_upload_limit_bytes();
$size = isset($file['size']) ? (int)$file['size'] : 0;
if ($size <= 0 || $size > $maxBytes) {
    promotion_upload_respond(400, array('error' => 'Promotion image must be 1 MB or smaller.'));
}

$currentTotalBytes = promotion_upload_get_usage_bytes();
$totalLimitBytes = promotion_upload_get_total_upload_limit_bytes();
if (($currentTotalBytes + $size) > $totalLimitBytes) {
    promotion_upload_respond(409, array(
        'error' => 'Promotion image storage has reached the 10 GB limit. Please contact admin to delete obsolete promotion images from the database before uploading a new image.',
        'totalBytes' => $currentTotalBytes,
        'limitBytes' => $totalLimitBytes,
        'remainingBytes' => max(0, $totalLimitBytes - $currentTotalBytes)
    ));
}

$tmpName = isset($file['tmp_name']) ? $file['tmp_name'] : '';
if ($tmpName === '' || !is_uploaded_file($tmpName)) {
    promotion_upload_respond(400, array('error' => 'Uploaded file is invalid'));
}

$finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
$mime = $finfo ? finfo_file($finfo, $tmpName) : '';
if ($finfo) {
    finfo_close($finfo);
}

$allowed = array(
    'image/png' => 'png',
    'image/jpeg' => 'jpg',
    'image/webp' => 'webp',
    'image/gif' => 'gif'
);

if (!isset($allowed[$mime])) {
    $imageInfo = @getimagesize($tmpName);
    $detectedMime = is_array($imageInfo) && isset($imageInfo['mime']) ? $imageInfo['mime'] : '';
    if (!isset($allowed[$detectedMime])) {
        promotion_upload_respond(400, array('error' => 'Only PNG, JPG, WEBP, and GIF images are allowed'));
    }
    $mime = $detectedMime;
}

$uploadDir = promotion_upload_get_upload_dir();
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0775, true);
}
if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
    promotion_upload_respond(500, array('error' => 'Promotion upload folder is not writable'));
}

$extension = $allowed[$mime];
$random = function_exists('random_bytes') ? bin2hex(random_bytes(8)) : substr(md5(uniqid('', true)), 0, 16);
$filename = 'promo-' . date('Ymd-His') . '-' . $random . '.' . $extension;
$destination = $uploadDir . '/' . $filename;

if (!move_uploaded_file($tmpName, $destination)) {
    promotion_upload_respond(500, array('error' => 'Failed to move uploaded file'));
}

@chmod($destination, 0644);

$uploadedAt = date('c');
$countryId = isset($_POST['countryId']) ? trim((string)$_POST['countryId']) : '';
$promotionId = isset($_POST['promotionId']) ? trim((string)$_POST['promotionId']) : '';
$promotionName = isset($_POST['promotionName']) ? trim((string)$_POST['promotionName']) : 'Promotion';
$historyEntry = null;

if ($countryId !== '') {
    list($db, $_path) = promotion_upload_read_db();
    foreach ($db['countries'] as $country) {
        if (!isset($country['id']) || (string)$country['id'] !== $countryId) {
            continue;
        }

        $historyPromotion = array(
            'id' => $promotionId !== '' ? $promotionId : ('upload-' . time()),
            'name' => $promotionName !== '' ? $promotionName : 'Promotion',
            'image' => '/menu/uploads/promotions/' . $filename,
            'imageFilename' => $filename,
            'imageSizeBytes' => $size,
            'uploadedAt' => $uploadedAt
        );

        $historyEntry = promotion_upload_build_history_entry('image_uploaded', $country, $historyPromotion);
        promotion_upload_append_history_entries($db, array($historyEntry));
        promotion_upload_write_db($db);
        break;
    }
}

$updatedTotalBytes = promotion_upload_get_usage_bytes();

promotion_upload_respond(200, array(
    'ok' => true,
    'url' => '/menu/uploads/promotions/' . $filename,
    'filename' => $filename,
    'imageSizeBytes' => $size,
    'uploadedAt' => $uploadedAt,
    'historyEntry' => $historyEntry,
    'totalBytes' => $updatedTotalBytes,
    'limitBytes' => $totalLimitBytes,
    'remainingBytes' => max(0, $totalLimitBytes - $updatedTotalBytes)
));
?>
