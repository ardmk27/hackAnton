<?php
declare(strict_types=1);

const SESSION_IDLE_SECONDS = 7200;   // sesi kedaluwarsa setelah 2 jam tidak aktif
const LOGIN_MAX_FAILS_IP   = 5;      // per IP+kode dalam 15 menit
const LOGIN_MAX_FAILS_CODE = 15;     // per kode Posyandu dalam 15 menit (semua IP)

/** Kode akses acak (kriptografis), alfabet tanpa karakter yang mudah tertukar. */
function generate_access_code(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < 12; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return implode('-', str_split($out, 4));
}

function normalize_access_code(string $s): string
{
    return preg_replace('/[^A-Z0-9]/', '', strtoupper($s)) ?? '';
}

function hash_access_code(string $code): string
{
    return password_hash(normalize_access_code($code), PASSWORD_DEFAULT);
}

/** ID Posyandu acak, dibuat server, tidak diturunkan dari nama. */
function generate_posyandu_code(PDO $pdo): string
{
    for ($i = 0; $i < 10; $i++) {
        $code = 'PSY-' . strtoupper(bin2hex(random_bytes(4)));
        $st = $pdo->prepare('SELECT 1 FROM posyandu WHERE posyandu_code = ?');
        $st->execute([$code]);
        if (!$st->fetchColumn()) {
            return $code;
        }
    }
    throw new RuntimeException('Gagal membuat ID Posyandu unik.');
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function login_blocked(PDO $pdo, string $code): bool
{
    $st = $pdo->prepare(
        'SELECT
           SUM(ip = ? AND posyandu_code = ?) AS by_ip,
           COUNT(*) AS by_code
         FROM login_attempts
         WHERE posyandu_code = ? AND attempted_at > (NOW() - INTERVAL 15 MINUTE)'
    );
    $st->execute([client_ip(), $code, $code]);
    $r = $st->fetch();
    return ((int)($r['by_ip'] ?? 0) >= LOGIN_MAX_FAILS_IP) || ((int)($r['by_code'] ?? 0) >= LOGIN_MAX_FAILS_CODE);
}

function record_failed_login(PDO $pdo, string $code): void
{
    $pdo->prepare('INSERT INTO login_attempts (ip, posyandu_code) VALUES (?, ?)')
        ->execute([client_ip(), $code]);
    // bersihkan catatan lama
    $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
}

function clear_failed_logins(PDO $pdo, string $code): void
{
    $pdo->prepare('DELETE FROM login_attempts WHERE ip = ? AND posyandu_code = ?')
        ->execute([client_ip(), $code]);
}

function login_posyandu(array $p): void
{
    session_regenerate_id(true);
    $_SESSION['posyandu_id']  = (int)$p['id'];
    $_SESSION['last_activity'] = time();
}

function logout_posyandu(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $c = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $c['path'], $c['domain'] ?? '', (bool)$c['secure'], true);
    }
    session_destroy();
}

/**
 * Wajib login. Mengembalikan baris Posyandu yang sedang login.
 * Semua query data harus memakai $p['id'] ini, bukan ID dari URL/form.
 */
function require_login(): array
{
    $id = $_SESSION['posyandu_id'] ?? null;
    $last = $_SESSION['last_activity'] ?? 0;
    if (!$id || (time() - (int)$last) > SESSION_IDLE_SECONDS) {
        if ($id) {
            logout_posyandu();
            session_start();
            flash('warning', 'Sesi Anda berakhir karena tidak aktif. Silakan masuk kembali.');
        }
        redirect('login.php');
    }
    $_SESSION['last_activity'] = time();

    $st = db()->prepare('SELECT id, posyandu_code, name FROM posyandu WHERE id = ?');
    $st->execute([(int)$id]);
    $p = $st->fetch();
    if (!$p) {
        logout_posyandu();
        redirect('login.php');
    }
    return $p;
}

function is_logged_in(): bool
{
    return !empty($_SESSION['posyandu_id']);
}
