<?php
declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** Hentikan permintaan jika token CSRF salah. Panggil pada setiap form yang mengubah data. */
function csrf_verify(): void
{
    $sent = (string)($_POST['csrf'] ?? '');
    if ($sent === '' || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('Token keamanan tidak valid atau kedaluwarsa. Silakan muat ulang halaman dan coba lagi.');
    }
}

function require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Metode tidak diizinkan.');
    }
}
