<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/ocr_runner.php';

$currentPosyandu = $p = require_login();
$pdo = db();
$pid = (int)$p['id'];

$modes = available_ocr_modes();
$defaultMode = default_ocr_mode();
$old = ['activity_date' => '', 'notes' => '', 'mode' => $defaultMode];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $errors = [];

    $old['activity_date'] = trim((string)($_POST['activity_date'] ?? ''));
    $old['notes'] = (string)($_POST['notes'] ?? '');
    $mode = (string)($_POST['mode'] ?? $defaultMode);
    $old['mode'] = $mode;
    if (!isset($modes[$mode]) || $mode === 'manual') {
        $errors[] = 'Mode pemrosesan tidak valid.';
    }

    [$activityDate, $err] = parse_date_ymd($old['activity_date']);
    if ($err) {
        $errors[] = 'Tanggal kegiatan: ' . $err;
    }
    $formType = (string)($_POST['form_type'] ?? FORM_BALITA);
    if (!in_array($formType, form_types(), true)) {
        $errors[] = 'Jenis formulir tidak dikenal.';
    }
    if ($mode === 'gemini' && empty($_POST['consent_ai'])) {
        $errors[] = 'Centang persetujuan pengiriman foto ke layanan AI (Gemini) untuk memakai mode ini.';
    }

    // --- Validasi file di SERVER (JS hanya kenyamanan) ---
    $file = $_FILES['photo'] ?? null;
    $mime = null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Pilih foto tabel terlebih dahulu.';
    } elseif ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        $errors[] = 'Ukuran foto melebihi batas server. Maksimal ' . env('MAX_UPLOAD_MB', '8') . ' MB.';
    } elseif ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        $errors[] = 'Unggahan foto gagal. Coba lagi.';
    } else {
        if ($file['size'] > max_upload_bytes() || $file['size'] <= 0) {
            $errors[] = 'Ukuran foto harus antara 1 byte dan ' . env('MAX_UPLOAD_MB', '8') . ' MB.';
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
        if (!isset($extMap[$mime]) || @getimagesize($file['tmp_name']) === false) {
            $errors[] = 'File harus berupa gambar JPG atau PNG yang valid.';
        }
    }

    if (!$errors) {
        $internal = bin2hex(random_bytes(16)) . '.' . $extMap[$mime];
        $dest = upload_dir() . '/' . $internal;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            $errors[] = 'Foto tidak dapat disimpan di server.';
        } else {
            @chmod($dest, 0640);
            $origName = clean_text(basename((string)$file['name']), 255);
            $pdo->prepare(
                "INSERT INTO sesi_foto (posyandu_id, activity_date, form_type, photo_path, original_filename, photo_mime, notes, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'uploaded')"
            )->execute([$pid, $activityDate, $formType, $internal, $origName, $mime, clean_text($old['notes'], 500)]);
            $sesiId = (int)$pdo->lastInsertId();

            $sesi = find_session($pdo, $sesiId, $pid);
            [$ok, $msg] = run_extraction($pdo, $sesi, $mode);
            if ($ok) {
                flash('success', $msg);
                redirect('review.php?id=' . $sesiId);
            }
            flash('danger', 'Foto tersimpan, tetapi pembacaan gagal: ' . $msg . ' Anda dapat mencoba ulang atau mengisi manual.');
            redirect('session_detail.php?id=' . $sesiId);
        }
    }
    foreach ($errors as $e) {
        flash('danger', $e);
    }
}

$pageTitle = 'Foto halaman register';
$extraScripts = ['assets/js/imagequality.js?v=2', 'assets/js/upload.js?v=2'];
require __DIR__ . '/includes/header.php';
$geminiOn = isset($modes['gemini']);
?>
<div class="loading-overlay" id="loading" role="alert" aria-live="assertive">
  <div class="kcf-spinner" aria-hidden="true"></div>
  <div class="fw-bold fs-5">Membaca register…</div>
  <ul class="kcf-steps">
    <li><?= icon('check', 18) ?>Mengunggah foto</li>
    <li><?= icon('check', 18) ?>Membaca tabel</li>
    <li><?= icon('check', 18) ?>Memeriksa angka dan menandai yang ragu</li>
  </ul>
  <div class="muted small">Mohon tunggu dan jangan tutup halaman. Bisa sampai 1 menit.</div>
</div>

<div class="mb-3">
  <h1 class="page-title h4 mb-1">Foto halaman register</h1>
  <p class="muted mb-0">Kertas tetap dipakai seperti biasa. Foto dibaca menjadi draf yang Anda periksa sebelum disimpan.</p>
</div>

<div class="row g-4">
  <div class="col-lg-7">
    <form method="post" enctype="multipart/form-data" id="upload-form" class="kcf-card">
      <div class="kcf-card-body">
        <?= csrf_field() ?>
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= max_upload_bytes() ?>">

        <div class="kcf-capture">
          <div class="kcf-capture-actions">
            <button type="button" class="btn btn-brand btn-lg" id="btn-camera"><?= icon('camera', 22) ?>Buka kamera</button>
            <button type="button" class="btn btn-quiet btn-lg" id="btn-gallery"><?= icon('image', 22) ?>Pilih dari galeri</button>
          </div>
          <button type="button" class="btn btn-link p-0 mt-2" id="btn-native" hidden>Pakai aplikasi kamera bawaan HP</button>
          <input type="file" id="photo" name="photo" accept="image/jpeg,image/png" class="visually-hidden" tabindex="-1" aria-label="Berkas foto" data-max="<?= max_upload_bytes() ?>">
          <input type="file" id="photo-native" accept="image/*" capture="environment" hidden tabindex="-1" aria-hidden="true">
          <div id="photo-status" class="small mt-2" role="status" aria-live="polite"></div>
          <div class="form-text mt-2">Letakkan buku rata, foto dari atas dan tegak lurus, cahaya merata, seluruh tabel masuk bingkai. Kamera langsung perlu izin kamera dan alamat HTTPS (localhost juga boleh).</div>
        </div>
        <div id="quality" class="kcf-quality mt-3" aria-live="polite"></div>
        <div id="size-note" class="small muted mt-2" aria-live="polite"></div>

        <div class="row g-3 mt-1">
          <div class="col-md-6">
            <label class="form-label" for="activity_date">Tanggal kegiatan (jika diketahui)</label>
            <input class="form-control" type="date" id="activity_date" name="activity_date" max="<?= date('Y-m-d') ?>" value="<?= e($old['activity_date']) ?>">
          </div>
        </div>

        <fieldset class="mt-3">
          <legend class="form-label">Jenis formulir</legend>
          <?php $selForm = form_key((string)($_POST['form_type'] ?? '')); ?>
          <div class="kcf-forms">
            <?php foreach (form_registry() as $fk => $f): ?>
              <label class="kcf-formopt">
                <input type="radio" name="form_type" value="<?= e($fk) ?>" <?= $fk === $selForm ? 'checked' : '' ?>>
                <span class="t"><?= e($f['label']) ?> <?= $f['verified'] ? '' : pill('Contoh kolom', 'warn') ?></span>
                <span class="d"><?= e($f['desc']) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="form-text">Pilih sesuai buku yang difoto. Kolom di buku yang tidak ada pada pilihan ini tidak dibaca. Kolom bertanda "Contoh" perlu dicocokkan dengan formulir Anda.</div>
        </fieldset>

        <details class="mt-3">
          <summary class="fw-bold" style="cursor:pointer">Catatan (opsional)</summary>
          <input class="form-control mt-2" id="notes" name="notes" maxlength="500" aria-label="Catatan" value="<?= e($old['notes']) ?>">
        </details>

        <div class="mt-3">
          <label class="form-label" for="mode">Cara membaca foto</label>
          <select class="form-select" id="mode" name="mode">
            <?php foreach ($modes as $k => $label): if ($k === 'manual') { continue; } ?>
              <option value="<?= e($k) ?>" <?= $old['mode'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-check mt-3" id="consent-wrap">
          <input class="form-check-input" type="checkbox" id="consent_ai" name="consent_ai" value="1">
          <label class="form-check-label small" for="consent_ai">
            Saya memahami foto ini akan <strong>dikirim ke layanan AI Google (Gemini)</strong> untuk dibaca,
            dan saya berwenang atau diizinkan memprosesnya. (Gunakan foto sintetis jika izin belum ada.)
          </label>
        </div>

        <button class="btn btn-brand btn-lg w-100 mt-4" id="submit-btn"><?= icon('camera', 20) ?>Unggah &amp; baca foto</button>
      </div>
    </form>
  </div>

  <div class="col-lg-5">
    <div class="kcf-card mb-3"><div class="kcf-card-body">
      <h2 class="h6">Pratinjau</h2>
      <div id="preview-empty" class="muted small">Pratinjau foto tampil di sini sebelum dikirim.</div>
      <img id="preview" class="kcf-preview d-none" alt="Pratinjau foto">
    </div></div>
    <?php if (!$geminiOn): ?>
      <div class="kcf-banner is-demo"><?= icon('flask', 20) ?><div><strong>Mode demo.</strong> Gemini belum aktif (<code>GEMINI_API_KEY</code> kosong di <code>.env</code>). Hasil adalah <u>data contoh sintetis</u>, bukan hasil membaca foto Anda.</div></div>
    <?php endif; ?>
    <div class="kcf-card"><div class="kcf-card-body">
      <h2 class="h6">Setelah diunggah</h2>
      <ol class="small mb-0 ps-3">
        <li>Sistem membaca tabel menjadi draf.</li>
        <li>Anda memeriksa hanya baris yang ditandai ragu.</li>
        <li>Setelah dikonfirmasi, data masuk rekap dan tren.</li>
      </ol>
    </div></div>
  </div>
</div>
<dialog class="kcf-cam" id="cam-dialog" aria-label="Kamera untuk memotret register">
  <div class="cam-stage">
    <video id="cam-video" playsinline muted autoplay></video>
    <img id="cam-shot" alt="Hasil foto" hidden>
    <div class="cam-guide" id="cam-guide" aria-hidden="true"></div>
    <div class="cam-chips" id="cam-chips" aria-live="polite"></div>
    <div class="cam-msg" id="cam-msg" role="alert" hidden></div>
  </div>
  <div class="cam-bar" id="cam-bar-live">
    <button type="button" class="cam-btn" id="cam-close">Batal</button>
    <button type="button" class="cam-shutter" id="cam-shutter" aria-label="Ambil foto"><span></span></button>
    <button type="button" class="cam-btn" id="cam-switch" aria-label="Ganti kamera" hidden>Ganti kamera</button>
    <span class="cam-spacer" id="cam-spacer"></span>
  </div>
  <div class="cam-bar" id="cam-bar-review" hidden>
    <button type="button" class="cam-btn" id="cam-retake">Ulangi</button>
    <button type="button" class="btn btn-brand btn-lg" id="cam-use"><?= icon('check', 20) ?>Pakai foto ini</button>
  </div>
</dialog>
<?php require __DIR__ . '/includes/footer.php'; ?>
