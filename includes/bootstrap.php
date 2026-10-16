<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/auth.php';

if (env('APP_DEBUG', 'false') === 'true') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_name('kcf_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
