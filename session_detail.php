<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/ocr_runner.php';
require_once __DIR__ . '/includes/xlsx.php';

$currentPosyandu = $p = require_login();
$pdo = db();
$pid = (int)$p['id'];

$sesiId = (int)($_GET['id'] ?? $_POST['sesi_id'] ?? 0);
$sesi = find_session($pdo, $sesiId, $pid) ?: abort_404();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    // Hapus foto sumber + hasil mentah OCR (kebijakan retensi). Data terkonfirmasi tetap ada.
    if ($action === 'delete_photo') {
        if ($sesi['photo_path']) {
            $f = upload_dir() . '/' . basename($sesi['photo_path']);
            if (is_file($f)) {
                @unlink($f);
            }
        }
        $pdo->prepare('UPDATE sesi_foto SET photo_path = NULL WHERE id = ? AND posyandu_id = ?')->execute([$sesiId, $pid]);
        $pdo->prepare('UPDATE ekstraksi SET raw_result = NULL WHERE sesi_foto_id = ?')->execute([$sesiId]);
        flash('success', 'Foto sumber dan hasil mentah OCR dihapus. Data pengukuran tidak berubah.');
        redirect('session_detail.php?id=' . $sesiId);
    }

    // Buka kembali sesi terkonfirmasi untuk direvisi: baris kembali menjadi DRAF, keluar dari rekap dan tren
    // sampai dikonfirmasi ulang. Alasan wajib dan dicatat.
    if ($action === 'reopen') {
        $reason = clean_text($_POST['reason'] ?? '', 300);
        if ($sesi['status'] !== 'confirmed') {
            flash('warning', 'Hanya sesi terkonfirmasi yang bisa dibuka kembali.');
            redirect('session_detail.php?id=' . $sesiId);
        }
        if ($reason === null || mb_strlen($reason) < 5) {
            flash('danger', 'Tuliskan alasan membuka kembali sesi (minimal 5 karakter), misalnya "salah baca BB anak nomor 5".');
            redirect('session_detail.php?id=' . $sesiId);
        }
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE pengukuran SET verification_status = 'draft', verified_at = NULL WHERE sesi_foto_id = ? AND posyandu_id = ?")->execute([$sesiId, $pid]);
            $pdo->prepare("UPDATE sesi_foto SET status = 'review', confirmed_at = NULL, reopened_at = NOW() WHERE id = ? AND posyandu_id = ?")->execute([$sesiId, $pid]);
            $pdo->prepare("INSERT INTO koreksi_log (posyandu_id, sesi_foto_id, action, detail) VALUES (?, ?, 'reopen', ?)")->execute([$pid, $sesiId, $reason]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[kader-cukup-foto] reopen: ' . $e->getMessage());
            flash('danger', 'Gagal membuka kembali sesi. Data tidak berubah.');
            redirect('session_detail.php?id=' . $sesiId);
        }
        flash('success', 'Sesi dibuka kembali sebagai draf. Data sesi ini tidak masuk rekap dan tren sampai dikonfirmasi ulang.');
        redirect('review.php?id=' . $sesiId);
    }
    redirect('session_detail.php?id=' . $sesiId);
}

$st = $pdo->prepare('SELECT * FROM pengukuran WHERE sesi_foto_id = ? AND posyandu_id = ? ORDER BY id');
$st->execute([$sesiId, $pid]);
$rows = $st->fetchAll();

$st = $pdo->prepare('SELECT action, detail, created_at FROM koreksi_log WHERE sesi_foto_id = ? AND posyandu_id = ? ORDER BY id DESC LIMIT 20');
$st->execute([$sesiId, $pid]);
$log = $st->fetchAll();

$confirmed = $sesi['status'] === 'confirmed';
$hasPhoto = !empty($sesi['photo_path']);
$modes = available_ocr_modes();
$isDemoData = in_array($sesi['ocr_engine'], ['mock', 'demo'], true);
$form = form_key($sesi['form_type'] ?? null);
$mRules = form_measure_rules($form);
$labels = ['nomor_pendaftaran' => 'No.', 'nama_peserta' => 'Nama', 'tanggal_lahir' => 'Tgl lahir', 'jenis_kelamin' => 'JK', 'periode_pengukuran' => 'Periode',
           'bb_kg' => 'BB', 'tb_pb_cm' => 'TB', 'lingkar_kepala_cm' => 'L. kepala', 'lila_cm' => 'LiLA', 'usia_kehamilan_minggu' => 'Usia kehamilan',
           'td_sistolik' => 'Sistolik', 'td_diastolik' => 'Diastolik', 'gula_darah_mgdl' => 'Gula darah', 'keterangan' => 'Keterangan'];
$corrected = 0;
foreach ($rows as $r) {
    if (!empty($r['corrected_fields'])) {
        $corrected++;
    }
}

$pageTitle = 'Detail sesi #' . $sesiId;
require __DIR__ . '/includes/header.php';
?>
<p class="mb-2"><a class="fw-bold text-decoration-none" href="history.php"><?= icon('back', 18) ?> Riwayat</a></p>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h1 class="page-title h4 mb-0">Detail sesi #<?= $sesiId ?></h1>
  <?= status_pill($sesi['status']) ?>
</div>

<?php if ($confirmed): ?>
  <div class="kcf-banner is-ok"><?= icon('check', 20) ?><div><strong>Terkonfirmasi</strong> pada <?= format_datetime($sesi['confirmed_at']) ?>. Data ini masuk rekap dan tren.</div></div>
<?php elseif ($sesi['status'] === 'failed'): ?>
  <div class="kcf-banner is-bad"><?= icon('alert', 20) ?><div><strong>Pembacaan gagal.</strong> <?= e($sesi['error_message'] ?: 'Terjadi kesalahan.') ?> Foto tetap tersimpan. Coba ulang, gunakan mode demo, atau isi manual.</div></div>
<?php else: ?>
  <div class="kcf-banner is-draft"><?= icon('edit', 20) ?><div><strong>Draf</strong>, belum masuk rekap sebelum dikonfirmasi.</div></div>
<?php endif; ?>
<?php if ($isDemoData): ?>
  <div class="kcf-banner is-demo"><?= icon('flask', 20) ?><div><strong>Mode demo.</strong> Isi sesi ini adalah data contoh sintetis, bukan hasil membaca foto.</div></div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-lg-5">
    <div class="kcf-card h-100"><div class="kcf-card-body">
      <dl class="kcf-kv">
        <dt>Tanggal kegiatan</dt><dd><?= format_date($sesi['activity_date']) ?></dd>
        <dt>Waktu unggah</dt><dd><?= format_datetime($sesi['created_at']) ?></dd>
        <dt>Formulir</dt><dd><?= e(form_type_label((string)$sesi['form_type'])) ?></dd>
        <dt>Cara baca</dt><dd><?= e($sesi['ocr_engine'] ?: '—') ?></dd>
        <dt>Jumlah baris</dt><dd><?= count($rows) ?></dd>
        <?php if ($confirmed && $corrected > 0): ?><dt>Dikoreksi kader</dt><dd><?= $corrected ?> baris</dd><?php endif; ?>
        <dt>Berkas</dt><dd><?= e($sesi['original_filename'] ?: '—') ?></dd>
        <?php if ($sesi['notes']): ?><dt>Catatan</dt><dd><?= e($sesi['notes']) ?></dd><?php endif; ?>
      </dl>
    </div></div>
  </div>
  <div class="col-lg-7">
    <div class="kcf-card h-100"><div class="kcf-card-body">
      <?php if ($hasPhoto): ?>
        <a href="photo.php?id=<?= $sesiId ?>" target="_blank" rel="noopener"><img src="photo.php?id=<?= $sesiId ?>" class="img-fluid rounded" style="max-height:220px" alt="Foto sumber (buka ukuran penuh)"></a>
      <?php else: ?>
        <div class="muted">Foto sumber tidak tersedia (dihapus, sesi manual, atau data demo).</div>
      <?php endif; ?>
    </div></div>
  </div>
</div>

<div class="d-flex flex-wrap gap-2 mb-3">
  <?php if (!$confirmed): ?>
    <a class="btn btn-brand" href="review.php?id=<?= $sesiId ?>"><?= icon('edit', 18) ?>Periksa &amp; koreksi</a>
  <?php else: ?>
    <a class="btn btn-brand" href="export_pdf.php?sesi_id=<?= $sesiId ?>"><?= icon('printer', 18) ?>Unduh PDF</a>
    <?php if (SimpleXlsx::available()): ?><a class="btn btn-quiet" href="export_xlsx.php?sesi_id=<?= $sesiId ?>"><?= icon('sheet', 18) ?>Unduh XLSX</a><?php else: ?><a class="btn btn-quiet" href="export_csv.php?sesi_id=<?= $sesiId ?>"><?= icon('download', 18) ?>Unduh CSV</a><?php endif; ?>
  <?php endif; ?>
  <?php if ($hasPhoto): ?>
    <form method="post" onsubmit="return confirm('Hapus foto sumber dan hasil mentah OCR? Tindakan ini tidak dapat dibatalkan. Data pengukuran tetap ada.');">
      <?= csrf_field() ?><input type="hidden" name="sesi_id" value="<?= $sesiId ?>"><input type="hidden" name="action" value="delete_photo">
      <button class="btn btn-danger-quiet"><?= icon('trash', 18) ?>Hapus foto &amp; hasil mentah</button>
    </form>
  <?php endif; ?>
</div>

<?php if ($confirmed): ?>
<details class="kcf-card mb-3">
  <summary class="kcf-card-head" style="cursor:pointer;list-style:none"><span><?= icon('undo', 18) ?> Ada yang salah? Buka kembali untuk revisi</span></summary>
  <div class="kcf-card-body">
    <p class="small muted">Baris sesi ini kembali menjadi draf dan <strong>keluar dari rekap dan tren</strong> sampai Anda mengonfirmasi ulang. Alasan dicatat pada jejak aktivitas.</p>
    <form method="post" class="row g-2 align-items-end" onsubmit="return confirm('Buka kembali sesi ini? Datanya keluar dari rekap dan tren sampai dikonfirmasi ulang.');">
      <?= csrf_field() ?><input type="hidden" name="sesi_id" value="<?= $sesiId ?>"><input type="hidden" name="action" value="reopen">
      <div class="col-md-9"><label class="form-label" for="reason">Alasan (wajib)</label><input class="form-control" id="reason" name="reason" maxlength="300" required minlength="5" placeholder="mis. salah baca BB anak nomor 5"></div>
      <div class="col-md-3"><button class="btn btn-quiet w-100">Buka kembali</button></div>
    </form>
  </div>
</details>
<?php else: ?>
<div class="kcf-card mb-3">
  <div class="kcf-card-head">Proses ulang</div>
  <div class="kcf-card-body">
    <p class="small muted mb-2">Memproses ulang <u>mengganti seluruh draf</u> sesi ini (data terkonfirmasi tidak terpengaruh).</p>
    <form method="post" action="process_photo.php" class="row g-2 align-items-end" onsubmit="return confirm('Draf saat ini akan diganti. Lanjutkan?');">
      <?= csrf_field() ?><input type="hidden" name="sesi_id" value="<?= $sesiId ?>">
      <div class="col-md-5">
        <label class="form-label" for="mode">Cara</label>
        <select class="form-select" name="mode" id="mode">
          <?php foreach ($modes as $k => $label): if ($k === 'gemini' && !$hasPhoto) { continue; } ?>
            <option value="<?= e($k) ?>"><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-5">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="consent_ai" value="1" id="consent_ai">
          <label class="form-check-label small" for="consent_ai">Saya setuju foto dikirim ke Gemini (hanya untuk mode Gemini)</label>
        </div>
      </div>
      <div class="col-md-2"><button class="btn btn-quiet w-100">Proses</button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="kcf-card mb-3">
  <div class="kcf-card-head"><?= $confirmed ? 'Data terkonfirmasi' : 'Data draf' ?></div>
  <?php if (!$rows): ?>
    <div class="kcf-empty">Belum ada baris.</div>
  <?php else: ?>
  <div class="kcf-scroll"><table class="kcf-ledger">
    <thead><tr><th>No.</th><th>Nama</th><th>Tgl lahir</th><?php if (form_shows($form, 'jenis_kelamin')): ?><th>JK</th><?php endif; ?><th>Periode</th>
      <?php foreach ($mRules as $mr): ?><th class="num"><?= e($mr['label']) ?></th><?php endforeach; ?>
      <th>Keterangan</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e($r['nomor_pendaftaran'] ?? '—') ?></td>
        <td><?= e($r['nama_peserta'] ?? '—') ?><?php if (!empty($r['corrected_fields'])): ?><div class="small muted">dikoreksi: <?= e(implode(', ', array_map(fn($f) => $labels[$f] ?? $f, explode(',', $r['corrected_fields'])))) ?></div><?php endif; ?></td>
        <td><?= format_date($r['tanggal_lahir']) ?></td>
        <?php if (form_shows($form, 'jenis_kelamin')): ?><td><?= e($r['jenis_kelamin'] ?? '—') ?></td><?php endif; ?>
        <td><?= e(format_period($r['measurement_period'])) ?></td>
        <?php foreach ($mRules as $mr): ?><td class="num"><?= e(show_value($r[$mr['col']] ?? null, (int)$mr['dec'])) ?></td><?php endforeach; ?>
        <td><?= e($r['keterangan'] ?? '') ?></td>
        <td><?= $r['verification_status'] === 'confirmed' ? pill('terverifikasi', 'ok', 'check') : pill('perlu_review', 'warn', 'edit') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php if ($log): ?>
<section class="kcf-card" aria-labelledby="h-log">
  <div class="kcf-card-head"><h2 id="h-log" class="h6 mb-0">Jejak aktivitas</h2><span class="muted small fw-normal">Kode akses dipakai bersama, jadi dicatat apa dan kapan, bukan siapa.</span></div>
  <div class="kcf-scroll"><table class="kcf-ledger">
    <thead><tr><th>Waktu</th><th>Kejadian</th><th>Keterangan</th></tr></thead>
    <tbody>
    <?php foreach ($log as $l): ?>
      <tr><td class="text-nowrap"><?= format_datetime($l['created_at']) ?></td><td><?= $l['action'] === 'confirm' ? pill('dikonfirmasi', 'ok', 'check') : pill('dibuka kembali', 'warn', 'undo') ?></td><td><?= e($l['detail'] ?? '') ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
