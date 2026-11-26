<?php
// অ্যাডমিন অ্যাক্সেস: লোকাল (127.0.0.1) থেকে সবসময় অনুমোদিত;
// অনলাইনে শুধু config.local.php-এ admin_password_hash সেট থাকলে লগইন করে।
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function isLocalDev() {
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost'], true);
}

function adminPasswordHash() {
    $f = __DIR__ . '/config.local.php';
    if (!file_exists($f)) return '';
    $c = include $f;
    return is_array($c) ? (string)($c['admin_password_hash'] ?? '') : '';
}

function isAdmin() {
    return isLocalDev() || !empty($_SESSION['is_admin']);
}

function adminLoginAttempt($password) {
    $hash = adminPasswordHash();
    if ($hash !== '' && password_verify($password, $hash)) {
        session_regenerate_id(true);
        $_SESSION['is_admin'] = true;
        return true;
    }
    return false;
}

function adminLogout() {
    unset($_SESSION['is_admin']);
}

// অনলাইনে ট্রাস্ট পেমেন্ট (যাচাই ছাড়া আনলক) শুধু অ্যাডমিনের জন্য
function trustPaymentAllowed() {
    return isAdmin();
}
