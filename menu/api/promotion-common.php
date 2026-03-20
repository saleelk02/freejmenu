<?php
function promotion_respond($status, $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function promotion_install_error_handlers() {
    set_error_handler(function($severity, $message, $file, $line) {
        promotion_respond(500, array(
            'error' => 'PHP runtime error',
            'message' => $message,
            'file' => basename($file),
            'line' => $line
        ));
    });

    set_exception_handler(function($e) {
        promotion_respond(500, array(
            'error' => 'Unhandled exception',
            'message' => $e->getMessage()
        ));
    });
}

function promotion_get_env_int($name, $default, $min = 1, $max = 604800) {
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

function promotion_get_admin_username() {
    $value = getenv('MENU_ADMIN_USERNAME');
    if ($value === false || $value === null || trim($value) === '') {
        return 'admin';
    }
    return trim($value);
}

function promotion_get_admin_password() {
    $value = getenv('MENU_ADMIN_PASSWORD');
    if ($value === false || $value === null || $value === '') {
        return 'ChangeMe123!';
    }
    return $value;
}

function promotion_get_promotion_username() {
    $value = getenv('MENU_PROMOTION_USERNAME');
    if ($value === false || $value === null || trim($value) === '') {
        return 'promotion';
    }
    return trim($value);
}

function promotion_get_promotion_password() {
    $value = getenv('MENU_PROMOTION_PASSWORD');
    if ($value === false || $value === null || $value === '') {
        return 'ChangePromotion123!';
    }
    return $value;
}

function promotion_get_admin_idle_timeout_seconds() {
    return promotion_get_env_int('MENU_ADMIN_IDLE_TIMEOUT', 900, 60, 86400);
}

function promotion_get_admin_session_ttl_seconds() {
    return promotion_get_env_int('MENU_ADMIN_SESSION_TTL', 43200, 300, 604800);
}

function promotion_get_idle_timeout_seconds() {
    return promotion_get_env_int('MENU_PROMOTION_IDLE_TIMEOUT', 900, 60, 86400);
}

function promotion_get_session_ttl_seconds() {
    return promotion_get_env_int('MENU_PROMOTION_SESSION_TTL', 43200, 300, 604800);
}

function promotion_clear_admin_auth() {
    unset($_SESSION['menu_admin_authenticated']);
    unset($_SESSION['menu_admin_username']);
    unset($_SESSION['menu_admin_logged_in_at']);
    unset($_SESSION['menu_admin_created_at']);
    unset($_SESSION['menu_admin_last_activity']);
}

function promotion_clear_auth() {
    unset($_SESSION['menu_promotion_authenticated']);
    unset($_SESSION['menu_promotion_username']);
    unset($_SESSION['menu_promotion_logged_in_at']);
    unset($_SESSION['menu_promotion_created_at']);
    unset($_SESSION['menu_promotion_last_activity']);
}

function promotion_enforce_admin_timeout() {
    if (empty($_SESSION['menu_admin_authenticated']) || $_SESSION['menu_admin_authenticated'] !== true) {
        return;
    }

    $now = time();
    $idleTimeout = promotion_get_admin_idle_timeout_seconds();
    $sessionTtl = promotion_get_admin_session_ttl_seconds();
    $lastActivity = isset($_SESSION['menu_admin_last_activity']) ? (int)$_SESSION['menu_admin_last_activity'] : $now;
    $createdAt = isset($_SESSION['menu_admin_created_at']) ? (int)$_SESSION['menu_admin_created_at'] : $now;

    if (($now - $lastActivity) > $idleTimeout || ($now - $createdAt) > $sessionTtl) {
        promotion_clear_admin_auth();
        return;
    }

    $_SESSION['menu_admin_last_activity'] = $now;
}

function promotion_enforce_timeout() {
    if (empty($_SESSION['menu_promotion_authenticated']) || $_SESSION['menu_promotion_authenticated'] !== true) {
        return;
    }

    $now = time();
    $idleTimeout = promotion_get_idle_timeout_seconds();
    $sessionTtl = promotion_get_session_ttl_seconds();
    $lastActivity = isset($_SESSION['menu_promotion_last_activity']) ? (int)$_SESSION['menu_promotion_last_activity'] : $now;
    $createdAt = isset($_SESSION['menu_promotion_created_at']) ? (int)$_SESSION['menu_promotion_created_at'] : $now;

    if (($now - $lastActivity) > $idleTimeout || ($now - $createdAt) > $sessionTtl) {
        promotion_clear_auth();
        return;
    }

    $_SESSION['menu_promotion_last_activity'] = $now;
}

function promotion_start_session() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        promotion_enforce_admin_timeout();
        promotion_enforce_timeout();
        return;
    }

    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $lifetime = max(
        promotion_get_admin_session_ttl_seconds(),
        promotion_get_session_ttl_seconds(),
        promotion_get_admin_idle_timeout_seconds(),
        promotion_get_idle_timeout_seconds()
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
    promotion_enforce_admin_timeout();
    promotion_enforce_timeout();
}

function promotion_is_admin_authenticated() {
    return !empty($_SESSION['menu_admin_authenticated']) && $_SESSION['menu_admin_authenticated'] === true;
}

function promotion_is_authenticated() {
    return !empty($_SESSION['menu_promotion_authenticated']) && $_SESSION['menu_promotion_authenticated'] === true;
}

function promotion_has_manager_access() {
    return promotion_is_admin_authenticated() || promotion_is_authenticated();
}

function promotion_set_admin_auth($username) {
    $now = time();
    $_SESSION['menu_admin_authenticated'] = true;
    $_SESSION['menu_admin_username'] = $username;
    $_SESSION['menu_admin_logged_in_at'] = date('c');
    $_SESSION['menu_admin_created_at'] = $now;
    $_SESSION['menu_admin_last_activity'] = $now;
}

function promotion_set_auth($username) {
    $now = time();
    $_SESSION['menu_promotion_authenticated'] = true;
    $_SESSION['menu_promotion_username'] = $username;
    $_SESSION['menu_promotion_logged_in_at'] = date('c');
    $_SESSION['menu_promotion_created_at'] = $now;
    $_SESSION['menu_promotion_last_activity'] = $now;
}

function promotion_destroy_session() {
    promotion_clear_auth();
    promotion_clear_admin_auth();

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

function promotion_get_auth_mode() {
    if (promotion_is_admin_authenticated()) {
        return 'admin';
    }
    if (promotion_is_authenticated()) {
        return 'promotion';
    }
    return null;
}

function promotion_get_auth_username() {
    if (promotion_is_admin_authenticated()) {
        return isset($_SESSION['menu_admin_username']) ? $_SESSION['menu_admin_username'] : promotion_get_admin_username();
    }
    if (promotion_is_authenticated()) {
        return isset($_SESSION['menu_promotion_username']) ? $_SESSION['menu_promotion_username'] : promotion_get_promotion_username();
    }
    return null;
}

function promotion_get_effective_idle_timeout_seconds() {
    return promotion_is_admin_authenticated() ? promotion_get_admin_idle_timeout_seconds() : promotion_get_idle_timeout_seconds();
}

function promotion_get_effective_session_ttl_seconds() {
    return promotion_is_admin_authenticated() ? promotion_get_admin_session_ttl_seconds() : promotion_get_session_ttl_seconds();
}

function promotion_parse_json_body() {
    $raw = @file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return array();
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        promotion_respond(400, array('error' => 'Invalid JSON body'));
    }

    return $decoded;
}

function promotion_get_storage_path() {
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

    promotion_respond(500, array(
        'error' => 'No writable storage path',
        'details' => 'Make /menu/data writable (775) and menu-db.json writable (664).'
    ));
}

function promotion_ensure_storage_file($path) {
    if (file_exists($path)) {
        return;
    }

    $seed = json_encode(array('countries' => array(), 'updatedAt' => date('c')), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $created = @file_put_contents($path, $seed, LOCK_EX);
    if ($created === false) {
        promotion_respond(500, array('error' => 'Failed to create database file', 'path' => $path));
    }
}

function promotion_read_db() {
    $path = promotion_get_storage_path();
    promotion_ensure_storage_file($path);

    $raw = @file_get_contents($path);
    if ($raw === false) {
        promotion_respond(500, array('error' => 'Failed to read database file', 'path' => $path));
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['countries']) || !is_array($decoded['countries'])) {
        $decoded = array('countries' => array(), 'updatedAt' => date('c'), 'promotionHistory' => array());
        @file_put_contents($path, json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
    }

    if (!isset($decoded['promotionHistory']) || !is_array($decoded['promotionHistory'])) {
        $decoded['promotionHistory'] = array();
    }

    return array($decoded, $path);
}

function promotion_write_db($db) {
    list($_current, $path) = promotion_read_db();
    if (!is_array($db)) {
        promotion_respond(400, array('error' => 'Invalid database payload'));
    }
    if (!isset($db['countries']) || !is_array($db['countries'])) {
        promotion_respond(400, array('error' => 'Invalid database payload: countries[] required'));
    }

    $db['updatedAt'] = date('c');
    $json = json_encode($db, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        promotion_respond(500, array('error' => 'Failed to encode database payload'));
    }

    $saved = @file_put_contents($path, $json, LOCK_EX);
    if ($saved === false) {
        promotion_respond(500, array('error' => 'Failed to save database file', 'path' => $path));
    }

    return array($db, $path);
}

function promotion_normalize_item($raw) {
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

function promotion_normalize_list($list) {
    if (!is_array($list)) {
        return array();
    }

    $normalized = array();
    foreach ($list as $item) {
        $promo = promotion_normalize_item($item);
        if ($promo['name'] === '') {
            $promo['name'] = 'Promotion';
        }
        $normalized[] = $promo;
    }

    return $normalized;
}

function promotion_get_upload_dir() {
    return dirname(__DIR__) . '/uploads/promotions';
}

function promotion_get_single_upload_limit_bytes() {
    return 1024 * 1024;
}

function promotion_get_total_upload_limit_bytes() {
    return 10 * 1024 * 1024 * 1024;
}

function promotion_get_upload_usage_bytes() {
    $uploadDir = promotion_get_upload_dir();
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

function promotion_get_country_name($country) {
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

function promotion_build_history_entry($action, $country, $promotion, $overrides = array()) {
    $image = isset($promotion['image']) ? (string)$promotion['image'] : '';
    $imageFilename = isset($promotion['imageFilename']) && $promotion['imageFilename'] !== ''
        ? (string)$promotion['imageFilename']
        : basename(parse_url($image, PHP_URL_PATH) ?: '');

    $entry = array(
        'id' => 'promotion-history-' . time() . '-' . substr(md5(uniqid('', true)), 0, 8),
        'action' => (string)$action,
        'countryId' => isset($country['id']) ? (string)$country['id'] : '',
        'countryName' => promotion_get_country_name($country),
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

    foreach ($overrides as $key => $value) {
        $entry[$key] = $value;
    }

    return $entry;
}

function promotion_append_history_entries(&$db, $entries) {
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

function promotion_items_equal($left, $right) {
    return json_encode(promotion_normalize_item($left), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        === json_encode(promotion_normalize_item($right), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
?>
