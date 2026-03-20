<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function promotion_save_respond($status, $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function promotion_save_get_env_int($name, $default, $min = 1, $max = 604800) {
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

function promotion_save_get_admin_idle_timeout_seconds() {
    return promotion_save_get_env_int('MENU_ADMIN_IDLE_TIMEOUT', 900, 60, 86400);
}

function promotion_save_get_admin_session_ttl_seconds() {
    return promotion_save_get_env_int('MENU_ADMIN_SESSION_TTL', 43200, 300, 604800);
}

function promotion_save_get_idle_timeout_seconds() {
    return promotion_save_get_env_int('MENU_PROMOTION_IDLE_TIMEOUT', 900, 60, 86400);
}

function promotion_save_get_session_ttl_seconds() {
    return promotion_save_get_env_int('MENU_PROMOTION_SESSION_TTL', 43200, 300, 604800);
}

function promotion_save_clear_admin_session() {
    unset($_SESSION['menu_admin_authenticated']);
    unset($_SESSION['menu_admin_username']);
    unset($_SESSION['menu_admin_logged_in_at']);
    unset($_SESSION['menu_admin_created_at']);
    unset($_SESSION['menu_admin_last_activity']);
}

function promotion_save_clear_promotion_session() {
    unset($_SESSION['menu_promotion_authenticated']);
    unset($_SESSION['menu_promotion_username']);
    unset($_SESSION['menu_promotion_logged_in_at']);
    unset($_SESSION['menu_promotion_created_at']);
    unset($_SESSION['menu_promotion_last_activity']);
}

function promotion_save_is_admin_authenticated() {
    return !empty($_SESSION['menu_admin_authenticated']) && $_SESSION['menu_admin_authenticated'] === true;
}

function promotion_save_is_promotion_authenticated() {
    return !empty($_SESSION['menu_promotion_authenticated']) && $_SESSION['menu_promotion_authenticated'] === true;
}

function promotion_save_has_manager_access() {
    return promotion_save_is_admin_authenticated() || promotion_save_is_promotion_authenticated();
}

function promotion_save_enforce_admin_timeout() {
    if (!promotion_save_is_admin_authenticated()) {
        return;
    }

    $now = time();
    $idleTimeout = promotion_save_get_admin_idle_timeout_seconds();
    $sessionTtl = promotion_save_get_admin_session_ttl_seconds();
    $lastActivity = isset($_SESSION['menu_admin_last_activity']) ? (int)$_SESSION['menu_admin_last_activity'] : $now;
    $createdAt = isset($_SESSION['menu_admin_created_at']) ? (int)$_SESSION['menu_admin_created_at'] : $now;

    if (($now - $lastActivity) > $idleTimeout || ($now - $createdAt) > $sessionTtl) {
        promotion_save_clear_admin_session();
        return;
    }

    $_SESSION['menu_admin_last_activity'] = $now;
}

function promotion_save_enforce_promotion_timeout() {
    if (!promotion_save_is_promotion_authenticated()) {
        return;
    }

    $now = time();
    $idleTimeout = promotion_save_get_idle_timeout_seconds();
    $sessionTtl = promotion_save_get_session_ttl_seconds();
    $lastActivity = isset($_SESSION['menu_promotion_last_activity']) ? (int)$_SESSION['menu_promotion_last_activity'] : $now;
    $createdAt = isset($_SESSION['menu_promotion_created_at']) ? (int)$_SESSION['menu_promotion_created_at'] : $now;

    if (($now - $lastActivity) > $idleTimeout || ($now - $createdAt) > $sessionTtl) {
        promotion_save_clear_promotion_session();
        return;
    }

    $_SESSION['menu_promotion_last_activity'] = $now;
}

function promotion_save_start_session() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        promotion_save_enforce_admin_timeout();
        promotion_save_enforce_promotion_timeout();
        return;
    }

    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $lifetime = max(
        promotion_save_get_admin_idle_timeout_seconds(),
        promotion_save_get_admin_session_ttl_seconds(),
        promotion_save_get_idle_timeout_seconds(),
        promotion_save_get_session_ttl_seconds()
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

    promotion_save_enforce_admin_timeout();
    promotion_save_enforce_promotion_timeout();
}

function promotion_save_parse_json_body() {
    $raw = @file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return array();
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        promotion_save_respond(400, array('error' => 'Invalid JSON body'));
    }

    return $decoded;
}

function promotion_save_get_storage_path() {
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

    promotion_save_respond(500, array(
        'error' => 'No writable storage path',
        'details' => 'Make /menu/data writable (775) and menu-db.json writable (664).'
    ));
}

function promotion_save_ensure_storage_file($path) {
    if (file_exists($path)) {
        return;
    }

    $seed = json_encode(array('countries' => array(), 'updatedAt' => date('c'), 'promotionHistory' => array()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $created = @file_put_contents($path, $seed, LOCK_EX);
    if ($created === false) {
        promotion_save_respond(500, array('error' => 'Failed to create database file', 'path' => $path));
    }
}

function promotion_save_read_db() {
    $path = promotion_save_get_storage_path();
    promotion_save_ensure_storage_file($path);

    $raw = @file_get_contents($path);
    if ($raw === false) {
        promotion_save_respond(500, array('error' => 'Failed to read database file', 'path' => $path));
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

function promotion_save_write_db($db) {
    list($_current, $path) = promotion_save_read_db();

    if (!is_array($db) || !isset($db['countries']) || !is_array($db['countries'])) {
        promotion_save_respond(400, array('error' => 'Invalid database payload'));
    }

    if (!isset($db['promotionHistory']) || !is_array($db['promotionHistory'])) {
        $db['promotionHistory'] = array();
    }

    $db['updatedAt'] = date('c');
    $json = json_encode($db, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        promotion_save_respond(500, array('error' => 'Failed to encode database payload'));
    }

    $saved = @file_put_contents($path, $json, LOCK_EX);
    if ($saved === false) {
        promotion_save_respond(500, array('error' => 'Failed to save database file', 'path' => $path));
    }

    return array($db, $path);
}

function promotion_save_normalize_item($raw) {
    if (!is_array($raw)) {
        $raw = array();
    }

    $seconds = isset($raw['displaySeconds']) ? (int)$raw['displaySeconds'] : 5;
    if ($seconds < 1) {
        $seconds = 1;
    }
    if ($seconds > 30) {
        $seconds = 30;
    }

    return array(
        'id' => trim((string)(isset($raw['id']) ? $raw['id'] : ('promo-' . time()))),
        'name' => trim((string)(isset($raw['name']) ? $raw['name'] : 'Promotion')),
        'image' => trim((string)(isset($raw['image']) ? $raw['image'] : '')),
        'imageFilename' => trim((string)(isset($raw['imageFilename']) ? $raw['imageFilename'] : '')),
        'imageSizeBytes' => max(0, isset($raw['imageSizeBytes']) ? (int)$raw['imageSizeBytes'] : 0),
        'uploadedAt' => trim((string)(isset($raw['uploadedAt']) ? $raw['uploadedAt'] : '')),
        'startAt' => trim((string)(isset($raw['startAt']) ? $raw['startAt'] : '')),
        'endAt' => trim((string)(isset($raw['endAt']) ? $raw['endAt'] : '')),
        'displaySeconds' => $seconds,
        'enabled' => !isset($raw['enabled']) || $raw['enabled'] !== false
    );
}

function promotion_save_normalize_list($list) {
    if (!is_array($list)) {
        return array();
    }

    $normalized = array();
    foreach ($list as $item) {
        $promotion = promotion_save_normalize_item($item);
        if ($promotion['name'] === '') {
            $promotion['name'] = 'Promotion';
        }
        $normalized[] = $promotion;
    }

    return $normalized;
}

function promotion_save_get_country_name($country) {
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

function promotion_save_build_history_entry($action, $country, $promotion) {
    $image = isset($promotion['image']) ? (string)$promotion['image'] : '';
    $imageFilename = isset($promotion['imageFilename']) && $promotion['imageFilename'] !== ''
        ? (string)$promotion['imageFilename']
        : basename(parse_url($image, PHP_URL_PATH) ?: '');

    return array(
        'id' => 'promotion-history-' . time() . '-' . substr(md5(uniqid('', true)), 0, 8),
        'action' => (string)$action,
        'countryId' => isset($country['id']) ? (string)$country['id'] : '',
        'countryName' => promotion_save_get_country_name($country),
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

function promotion_save_append_history_entries(&$db, $entries) {
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

function promotion_save_items_equal($left, $right) {
    return json_encode(promotion_save_normalize_item($left), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        === json_encode(promotion_save_normalize_item($right), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

set_error_handler(function($severity, $message, $file, $line) {
    promotion_save_respond(500, array(
        'error' => 'PHP runtime error',
        'message' => $message,
        'file' => basename($file),
        'line' => $line
    ));
});

set_exception_handler(function($e) {
    promotion_save_respond(500, array(
        'error' => 'Unhandled exception',
        'message' => $e->getMessage()
    ));
});

promotion_save_start_session();

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!promotion_save_has_manager_access()) {
    promotion_save_respond(401, array('error' => 'Unauthorized'));
}

if ($method !== 'POST') {
    promotion_save_respond(405, array('error' => 'Method not allowed'));
}

$body = promotion_save_parse_json_body();
$action = isset($body['action']) ? trim((string)$body['action']) : 'save';

if ($action === 'delete_history_item') {
    $historyId = isset($body['historyId']) ? trim((string)$body['historyId']) : '';
    if ($historyId === '') {
        promotion_save_respond(400, array('error' => 'historyId is required'));
    }

    list($db, $_path) = promotion_save_read_db();
    $existingHistory = isset($db['promotionHistory']) && is_array($db['promotionHistory']) ? $db['promotionHistory'] : array();
    $nextHistory = array_values(array_filter($existingHistory, function ($entry) use ($historyId) {
        return !is_array($entry) || !isset($entry['id']) || (string)$entry['id'] !== $historyId;
    }));

    if (count($nextHistory) === count($existingHistory)) {
        promotion_save_respond(404, array('error' => 'History entry not found'));
    }

    $db['promotionHistory'] = $nextHistory;
    list($savedDb, $_savedPath) = promotion_save_write_db($db);

    promotion_save_respond(200, array(
        'ok' => true,
        'updatedAt' => $savedDb['updatedAt'],
        'promotionHistory' => $savedDb['promotionHistory']
    ));
}

if ($action === 'clear_history') {
    list($db, $_path) = promotion_save_read_db();
    $db['promotionHistory'] = array();
    list($savedDb, $_savedPath) = promotion_save_write_db($db);

    promotion_save_respond(200, array(
        'ok' => true,
        'updatedAt' => $savedDb['updatedAt'],
        'promotionHistory' => array()
    ));
}

$countryId = isset($body['countryId']) ? trim((string)$body['countryId']) : '';
$promotions = isset($body['promotions']) && is_array($body['promotions']) ? $body['promotions'] : null;

if ($countryId === '' || $promotions === null) {
    promotion_save_respond(400, array('error' => 'countryId and promotions[] are required'));
}

list($db, $_path) = promotion_save_read_db();
$found = false;
$normalizedPromotions = promotion_save_normalize_list($promotions);
$historyEntries = array();

foreach ($db['countries'] as $index => $country) {
    if (!isset($country['id']) || (string)$country['id'] !== $countryId) {
        continue;
    }

    $existingPromotions = promotion_save_normalize_list(isset($country['promotions']) ? $country['promotions'] : array());
    $existingById = array();
    foreach ($existingPromotions as $existingPromotion) {
        $existingById[$existingPromotion['id']] = $existingPromotion;
    }

    $nextById = array();
    foreach ($normalizedPromotions as $nextPromotion) {
        $nextById[$nextPromotion['id']] = $nextPromotion;

        if (!isset($existingById[$nextPromotion['id']])) {
            $historyEntries[] = promotion_save_build_history_entry('created', $country, $nextPromotion);
            continue;
        }

        if (!promotion_save_items_equal($existingById[$nextPromotion['id']], $nextPromotion)) {
            $historyEntries[] = promotion_save_build_history_entry('updated', $country, $nextPromotion);
        }
    }

    foreach ($existingPromotions as $existingPromotion) {
        if (!isset($nextById[$existingPromotion['id']])) {
            $historyEntries[] = promotion_save_build_history_entry('deleted', $country, $existingPromotion);
        }
    }

    $db['countries'][$index]['promotions'] = $normalizedPromotions;
    $found = true;
    break;
}

if (!$found) {
    promotion_save_respond(404, array('error' => 'Country not found'));
}

promotion_save_append_history_entries($db, $historyEntries);
list($savedDb, $_savedPath) = promotion_save_write_db($db);

promotion_save_respond(200, array(
    'ok' => true,
    'updatedAt' => $savedDb['updatedAt'],
    'promotions' => $normalizedPromotions,
    'historyEntries' => $historyEntries
));
?>
