<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/ocr_runner.php';

/**
 * Proses/ulang proses sebuah sesi (dari halaman detail sesi).
 * mode: gemini | mock | manual. Draf lama diganti; data terkonfirmasi tidak dapat diproses ulang.
 */
$p = require_login();
require_post();
csrf_verify();
$pdo = db();

$sesiId = (int)($_POST['sesi_id'] ?? 0);
$sesi = find_session($pdo, $sesiId, (int)$p['id']) ?: abort_404();

$mode = (string)($_POST['mode'] ?? '');
$modes = available_ocr_modes();
if (!isset($modes[$mode])) {
    flash('danger', 'Mode pemrosesan tidak valid.');
    redirect('session_detail.php?id=' . $sesiId);
}
if ($mode === 'gemini' && empty($_POST['consent_ai'])) {
    flash('danger', 'Centang persetujuan pengiriman foto ke layanan AI untuk memakai Gemini.');
    redirect('session_detail.php?id=' . $sesiId);
}
if ($mode !== 'manual' && !$sesi['photo_path'] && $mode === 'gemini') {
    flash('danger', 'Foto sumber sudah dihapus; gunakan input manual.');
    redirect('session_detail.php?id=' . $sesiId);
}

[$ok, $msg] = run_extraction($pdo, $sesi, $mode);
if ($ok) {
    flash('success', $msg);
    redirect('review.php?id=' . $sesiId);
}
flash('danger', 'Pembacaan gagal: ' . $msg);
redirect('session_detail.php?id=' . $sesiId);
