<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/demo_seed.php';

/** Muat data demo sintetis ke Posyandu yang login. Hanya jika DEMO_SEED_ENABLED=true dan Posyandu masih kosong. */
$p = require_login();
require_post();
csrf_verify();
$pdo = db();
$pid = (int)$p['id'];

if (env('DEMO_SEED_ENABLED', 'false') !== 'true') {
    flash('danger', 'Data demo dinonaktifkan. Aktifkan DEMO_SEED_ENABLED=true di .env jika memang diperlukan.');
    redirect('dashboard.php');
}
$st = $pdo->prepare('SELECT COUNT(*) FROM sesi_foto WHERE posyandu_id = ?');
$st->execute([$pid]);
if ((int)$st->fetchColumn() > 0) {
    flash('warning', 'Data demo hanya dapat dimuat ke Posyandu yang masih kosong.');
    redirect('dashboard.php');
}

$res = seed_demo_data($pdo, $pid, (string)($_POST['mode'] ?? 'story'));
if ($res['draft_session_id']) {
    flash('success', 'Data demo dimuat (sintetis). Buka sesi Oktober yang berstatus draf untuk mencoba memeriksa dan mengonfirmasi.');
    redirect('review.php?id=' . (int)$res['draft_session_id']);
}
flash('success', 'Data demo lengkap dimuat (sintetis, 3 bulan terkonfirmasi).');
redirect('attention.php');
