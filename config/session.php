<?php
/**
 * Session Handler & Access Control Helpers
 */
require_once __DIR__ . '/env.php';

if (!defined('APP_BASE_URL')) {
    $projectRoot = realpath(__DIR__ . '/..');
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    $detectedBase = '';
    if ($projectRoot && $documentRoot && strpos(str_replace('\\', '/', $projectRoot), str_replace('\\', '/', $documentRoot)) === 0) {
        $relative = trim(str_replace('\\', '/', substr(str_replace('\\', '/', $projectRoot), strlen(str_replace('\\', '/', $documentRoot)))), '/');
        $detectedBase = $relative !== '' ? '/' . $relative : '';
    }

    $rawBase = rtrim((string)(getenv('HRIS_BASE_URL') ?: (getenv('APP_URL') ?: '')), '/');
    if ($rawBase !== '') {
        $parsedPath = parse_url($rawBase, PHP_URL_PATH) ?: '';
        if ($detectedBase !== '' && !str_ends_with($parsedPath, $detectedBase)) {
            $baseUrl = $rawBase . $detectedBase;
        } else {
            $baseUrl = $rawBase;
        }
    } else {
        $baseUrl = $detectedBase;
    }
    define('APP_BASE_URL', $baseUrl);
}

// HostForge Resilience Pattern 3: Edge Reverse Proxy & Protocol Detection
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
    || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;

// HostForge Resilience Pattern 4: Ephemeral Directory Bootstrap
$uploadDir = __DIR__ . '/../assets/uploads';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', '1');
    if ($isHttps) {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
}

function siteUrl($path = '') {
    return APP_BASE_URL . '/' . ltrim($path, '/');
}

function assetUrl($path = '') {
    return siteUrl($path);
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: " . siteUrl('auth/login.php'));
        exit;
    }
}

function requireRole($role) {
    requireLogin();
    // Super Admin inherits all HR Administrator privileges.
    $allowed = $_SESSION['role'] === $role || ($role === 'HR Administrator' && $_SESSION['role'] === 'Super Admin');
    if (!$allowed) {
        header("Location: " . siteUrl('modules/dashboard/index.php?error=unauthorized'));
        exit;
    }
}

function isAdmin() {
    return isLoggedIn() && in_array($_SESSION['role'], ['HR Administrator','Super Admin'], true);
}

function isSuperAdmin() {
    return isLoggedIn() && $_SESSION['role'] === 'Super Admin';
}

function currentEmployeeId() {
    return $_SESSION['employee_id'] ?? null;
}

function redirect($path) {
    $target = $path;
    if (strpos($path, 'http://') !== 0 && strpos($path, 'https://') !== 0) {
        $target = strpos($path, '/') === 0 ? APP_BASE_URL . $path : siteUrl($path);
    }
    header("Location: " . $target);
    exit;
}

function flash($key, $message = null) {
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return;
    }
    if (isset($_SESSION['flash'][$key])) {
        $msg = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $msg;
    }
    return null;
}
