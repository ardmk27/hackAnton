<?php
declare(strict_types=1);

/**
 * Buat Posyandu demo + data sintetis 3 bulan dari terminal.
 *   php database/seed_demo.php          → skenario "story" (Oktober berstatus draf)
 *   php database/seed_demo.php full     → ketiga bulan terkonfirmasi
 * Mencetak ID Posyandu dan kode akses (hanya muncul sekali).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Jalankan lewat terminal.');
}
$_SESSION = [];
require __DIR__ . '/../config/env.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/validation.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/demo_seed.php';

$pdo = db();
$mode = ($argv[1] ?? 'story') === 'full' ? 'full' : 'story';
$access = generate_access_code();
$code = generate_posyandu_code($pdo);
$pdo->prepare('INSERT INTO posyandu (posyandu_code, name, credential_hash) VALUES (?, ?, ?)')
    ->execute([$code, 'Posyandu Demo', hash_access_code($access)]);
$pid = (int)$pdo->lastInsertId();
$res = seed_demo_data($pdo, $pid, $mode);

echo "Posyandu Demo dibuat (data sintetis, mode: $mode)\n";
echo "  ID Posyandu : $code\n";
echo "  Kode akses  : $access   (catat sekarang, tidak ditampilkan lagi)\n";
echo "  Anak        : {$res['children']}, sesi terkonfirmasi: {$res['confirmed_sessions']}";
echo $res['draft_session_id'] ? ", sesi draf: #{$res['draft_session_id']}\n" : "\n";
