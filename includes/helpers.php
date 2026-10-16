<?php
declare(strict_types=1);

require_once __DIR__ . '/forms.php';

function e(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function pull_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/** [label, kelas badge Bootstrap] untuk status sesi (dipertahankan untuk kompatibilitas). */
function status_badge(string $status): array
{
    return match ($status) {
        'uploaded'   => ['Foto terunggah', 'secondary'],
        'processing' => ['Sedang diproses', 'info'],
        'review'     => ['Perlu diperiksa', 'warning'],
        'confirmed'  => ['Terkonfirmasi', 'success'],
        'failed'     => ['Gagal dibaca', 'danger'],
        default      => [$status, 'light'],
    };
}

/** Lencana status sesi dengan ikon + teks (tampilan baru). */
function status_pill(string $status): string
{
    return match ($status) {
        'uploaded'   => pill('Foto terunggah', 'muted', 'image'),
        'processing' => pill('Sedang diproses', 'info', 'clock'),
        'review'     => pill('Perlu diperiksa', 'warn', 'edit'),
        'confirmed'  => pill('Terkonfirmasi', 'ok', 'check'),
        'failed'     => pill('Gagal dibaca', 'bad', 'alert'),
        default      => pill($status, 'muted'),
    };
}

function form_type_label(string $t): string
{
    return form_label($t);
}

/** Daftar kunci formulir yang diizinkan (dari registri). */
function form_types(): array
{
    return array_keys(form_registry());
}

/** Format angka ala Indonesia untuk tampilan (koma desimal, tanpa nol di belakang). */
function show_num(mixed $v): string
{
    if ($v === null || $v === '') {
        return '—';
    }
    $s = str_replace('.', ',', rtrim(rtrim((string)$v, '0'), '.'));
    return str_contains($s, ',') ? $s : $s . ',0';
}

/** Nilai ukur untuk tampilan: bulat jika tanpa desimal (tekanan darah), selain itu minimal satu desimal. */
function show_value(mixed $v, int $dec = 1): string
{
    if ($v === null || $v === '') {
        return '—';
    }
    return $dec === 0 ? (string)(int)round((float)$v) : show_num($v);
}

function format_period(?string $p): string
{
    if (!$p || !preg_match('/^(\d{4})-(\d{2})$/', $p, $m)) {
        return '—';
    }
    $bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    return ($bulan[(int)$m[2]] ?? $m[2]) . ' ' . $m[1];
}

function format_date(?string $d): string
{
    if (!$d) {
        return '—';
    }
    $t = strtotime($d);
    return $t ? date('d/m/Y', $t) : e($d);
}

function format_datetime(?string $d): string
{
    if (!$d) {
        return '—';
    }
    $t = strtotime($d);
    return $t ? date('d/m/Y H:i', $t) : e($d);
}

function max_upload_bytes(): int
{
    return (int)(((float)env('MAX_UPLOAD_MB', '8')) * 1024 * 1024);
}

function upload_dir(): string
{
    $d = env('UPLOAD_DIR');
    $dir = $d ?: dirname(__DIR__) . '/storage/uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    return rtrim($dir, '/\\');
}

/** Ambil sesi milik Posyandu yang sedang login. Mengetahui ID saja tidak cukup. */
function find_session(PDO $pdo, int $sesiId, int $posyanduId): ?array
{
    $st = $pdo->prepare('SELECT * FROM sesi_foto WHERE id = ? AND posyandu_id = ?');
    $st->execute([$sesiId, $posyanduId]);
    return $st->fetch() ?: null;
}

function abort_404(): never
{
    http_response_code(404);
    exit('Data tidak ditemukan.');
}
