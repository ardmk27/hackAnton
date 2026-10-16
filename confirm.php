<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/measurements.php';
require_once __DIR__ . '/includes/suggest.php';

/**
 * Simpan draf atau konfirmasi data dari halaman review.
 * Backend memvalidasi ulang SEMUA nilai; tidak ada konfirmasi otomatis.
 */
$p = require_login();
require_post();
csrf_verify();
$pdo = db();
$pid = (int)$p['id'];

$sesiId = (int)($_POST['sesi_id'] ?? 0);
$sesi = find_session($pdo, $sesiId, $pid) ?: abort_404();
if ($sesi['status'] === 'confirmed') {
    flash('warning', 'Sesi ini sudah terkonfirmasi.');
    redirect('session_detail.php?id=' . $sesiId);
}

$action = (string)($_POST['action'] ?? '');
if (!in_array($action, ['save', 'confirm'], true)) {
    abort_404();
}
$confirm = $action === 'confirm';

$form = form_key($sesi['form_type'] ?? null);
$subject = form_def($form)['subject'];
$submitted = is_array($_POST['rows'] ?? null) ? array_slice($_POST['rows'], 0, 500, true) : [];
$clean = [];
$errors = [];
$keptRaw = [];
foreach ($submitted as $key => $r) {
    if (!is_array($r)) {
        continue;
    }
    $key = (string)$key;
    $keptRaw[$key] = $r;
    $res = validate_row($r, $confirm, $form);
    if ($res['empty']) {
        unset($keptRaw[$key]);
        continue;
    }
    if ($res['errors']) {
        $errors[$key] = $res['errors'];
    }
    $clean[$key] = $res['clean'];
}

if ($confirm && $clean) {
    // Nama kembar: nama saja tidak boleh menyatukan riwayat dua orang. Wajib dibedakan sebelum dikonfirmasi.
    $rosterForCheck = load_roster($pdo, $pid, $form);
    foreach ($clean as $k => $row) {
        $amb = name_ambiguity($rosterForCheck, ['nomor_pendaftaran' => $row['nomor_pendaftaran'], 'nama_peserta' => $row['nama_peserta'], 'tanggal_lahir' => $row['tanggal_lahir']]);
        if ($amb['status'] === 'ambiguous' && count($amb['candidates']) >= 2) {
            $errors[$k][] = 'Nama sama dengan ' . count($amb['candidates']) . ' ' . $subject . ' yang sudah ada (' . implode('; ', array_map('person_hint', $amb['candidates'])) . '). Pilih yang benar, atau isi no. daftar atau tanggal lahir.';
        }
    }
    foreach (session_duplicate_errors($clean) as $k => $msgs) {
        foreach ($msgs as $m) {
            $errors[$k][] = $m;
        }
    }
}

if ($confirm && !$clean && !$errors) {
    $errors['_'] = ['Tidak ada baris untuk dikonfirmasi.'];
}

if ($errors) {
    $_SESSION['review_old'][$sesiId] = ['rows' => $keptRaw ?: ['r0' => []], 'errors' => $errors];
    if (isset($errors['_'])) {
        flash('danger', $errors['_'][0]);
    } else {
        flash('danger', 'Periksa kembali baris yang ditandai merah. Tidak ada data yang disimpan.');
    }
    redirect('review.php?id=' . $sesiId);
}

$correctedRows = count(array_filter($clean, fn($r) => !empty($r['corrected_fields'])));

try {
    $pdo->beginTransaction();
    // Kunci sesi agar konfirmasi ganda (klik dua kali) tidak menggandakan data
    $st = $pdo->prepare('SELECT status FROM sesi_foto WHERE id = ? AND posyandu_id = ? FOR UPDATE');
    $st->execute([$sesiId, $pid]);
    if ($st->fetchColumn() === 'confirmed') {
        $pdo->rollBack();
        flash('warning', 'Sesi ini sudah terkonfirmasi.');
        redirect('session_detail.php?id=' . $sesiId);
    }

    $pdo->prepare("DELETE FROM pengukuran WHERE sesi_foto_id = ? AND posyandu_id = ? AND verification_status = 'draft'")
        ->execute([$sesiId, $pid]);

    foreach ($clean as $row) {
        if ($confirm) {
            $pesertaId = link_peserta($pdo, $pid, $row, $form);
            insert_measurement($pdo, $pid, $sesiId, $row, 'confirmed', $pesertaId);
        } else {
            insert_measurement($pdo, $pid, $sesiId, $row, 'draft');
        }
    }

    if ($confirm) {
        $pdo->prepare("UPDATE sesi_foto SET status = 'confirmed', confirmed_at = NOW() WHERE id = ? AND posyandu_id = ?")
            ->execute([$sesiId, $pid]);
        $pdo->prepare("INSERT INTO koreksi_log (posyandu_id, sesi_foto_id, action, detail) VALUES (?, ?, 'confirm', ?)")
            ->execute([$pid, $sesiId, count($clean) . ' baris dikonfirmasi; ' . $correctedRows . ' baris dikoreksi dari hasil baca AI']);
    } else {
        $pdo->prepare("UPDATE sesi_foto SET status = 'review' WHERE id = ? AND posyandu_id = ?")
            ->execute([$sesiId, $pid]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[kader-cukup-foto] confirm: ' . $e->getMessage());
    flash('danger', 'Gagal menyimpan. Data Anda belum berubah; coba lagi.');
    redirect('review.php?id=' . $sesiId);
}

if ($confirm) {
    flash('success', count($clean) . ' baris dikonfirmasi dan masuk rekap dan tren' . ($correctedRows > 0 ? ' (' . $correctedRows . ' baris Anda koreksi dari hasil baca AI).' : '.'));
    redirect('session_detail.php?id=' . $sesiId);
}
flash('success', 'Draf disimpan (' . count($clean) . ' baris). Belum masuk rekap.');
redirect('review.php?id=' . $sesiId);
