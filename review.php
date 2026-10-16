<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/row_form.php';
require_once __DIR__ . '/includes/suggest.php';

$currentPosyandu = $p = require_login();
$pdo = db();
$pid = (int)$p['id'];

$sesiId = (int)($_GET['id'] ?? 0);
$sesi = find_session($pdo, $sesiId, $pid) ?: abort_404();
if ($sesi['status'] === 'confirmed') {
    redirect('session_detail.php?id=' . $sesiId);
}

// Data hasil submit yang gagal validasi (dipertahankan agar input pengguna tidak hilang)
$old = $_SESSION['review_old'][$sesiId] ?? null;
unset($_SESSION['review_old'][$sesiId]);

if ($old) {
    $rows = $old['rows'];
    $rowErrors = $old['errors'];
} else {
    $st = $pdo->prepare("SELECT * FROM pengukuran WHERE sesi_foto_id = ? AND posyandu_id = ? AND verification_status = 'draft' ORDER BY id");
    $st->execute([$sesiId, $pid]);
    $rows = [];
    foreach ($st->fetchAll() as $i => $r) {
        $rows['r' . $i] = $r;
    }
    $rowErrors = [];
}
if (!$rows) {
    $rows = ['r0' => []];
}

$form = form_key($sesi['form_type'] ?? null);
$formDef = form_def($form);
$roster = load_roster($pdo, $pid, $form);
$ctx = ['roster' => $roster, 'sesi_id' => $sesiId, 'form' => $form];
$isDemoData = in_array($sesi['ocr_engine'], ['mock', 'demo'], true);
$hasPhoto = !empty($sesi['photo_path']);

$pageTitle = 'Periksa & koreksi';
$extraScripts = ['assets/js/review.js?v=2'];
require __DIR__ . '/includes/header.php';
?>
<p class="mb-2"><a class="fw-bold text-decoration-none" href="session_detail.php?id=<?= $sesiId ?>"><?= icon('back', 18) ?> Detail sesi #<?= $sesiId ?></a></p>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
  <div>
    <h1 class="page-title h4 mb-1">Periksa &amp; koreksi <?= pill($formDef['short'], 'info') ?></h1>
    <p class="muted mb-0">Bandingkan dengan foto sumber. Kolom kuning perlu dicek; kolom kosong tetap kosong (bukan 0).</p>
  </div>
</div>

<?php if (!$formDef['verified']): ?>
  <div class="kcf-banner is-info"><?= icon('info', 20) ?><div><strong>Kolom contoh.</strong> Kolom untuk "<?= e($formDef['label']) ?>" adalah contoh awal. Cocokkan dengan buku di Posyandu Anda sebelum memakainya untuk data nyata.</div></div>
<?php endif; ?>
<div class="kcf-banner is-draft">
  <?= icon('edit', 20) ?>
  <div><strong>DRAF, belum terkonfirmasi.</strong> Data ini belum masuk rekap dan tren. Setelah semua baris yang ditandai Anda periksa, tekan <strong>Konfirmasi data</strong>.<?= $sesi['reopened_at'] ? ' Sesi ini dibuka kembali untuk revisi pada ' . e(format_datetime($sesi['reopened_at'])) . '.' : '' ?></div>
</div>
<?php if ($isDemoData): ?>
  <div class="kcf-banner is-demo"><?= icon('flask', 20) ?><div><strong>MODE DEMO.</strong> Isi di bawah adalah <u>contoh sintetis</u>, bukan hasil membaca foto Anda.</div></div>
<?php endif; ?>

<div class="kcf-review<?= $hasPhoto ? '' : ' no-photo' ?>">
  <?php if ($hasPhoto): ?>
  <aside class="d-none d-lg-block" aria-label="Foto sumber">
    <div class="kcf-sticky kcf-card">
      <div class="kcf-card-head"><span>Foto sumber</span>
        <span class="btn-group btn-group-sm" role="group" aria-label="Zoom foto">
          <button type="button" class="btn btn-quiet js-zoom" data-dir="-1" aria-label="Perkecil">−</button>
          <button type="button" class="btn btn-quiet js-zoom" data-dir="0" aria-label="Ukuran asli"><span class="js-zoom-label">100%</span></button>
          <button type="button" class="btn btn-quiet js-zoom" data-dir="1" aria-label="Perbesar">+</button>
        </span>
      </div>
      <div class="kcf-card-body p-2"><div class="kcf-photo"><img class="js-photo-img" src="photo.php?id=<?= $sesiId ?>" alt="Foto tabel sumber" style="width:100%"></div></div>
    </div>
  </aside>
  <?php endif; ?>

  <div>
    <?php if ($hasPhoto): ?>
      <button type="button" class="btn btn-quiet w-100 mb-3 d-lg-none" id="open-photo"><?= icon('image', 18) ?>Lihat foto sumber</button>
    <?php endif; ?>

    <form method="post" action="confirm.php" id="review-form">
      <?= csrf_field() ?>
      <input type="hidden" name="sesi_id" value="<?= $sesiId ?>">

      <?php if ($rowErrors): ?>
        <div class="kcf-banner is-bad" role="alert"><?= icon('x', 20) ?><div>Ada baris yang perlu diperbaiki (ditandai merah). Tidak ada yang disimpan.</div></div>
      <?php endif; ?>

      <div class="kcf-review-bar">
        <div class="kcf-seg" role="group" aria-label="Tampilan baris">
          <button type="button" class="is-on" data-filter="cek" aria-pressed="true">Perlu dicek (<span id="n-cek">0</span>)</button>
          <button type="button" data-filter="all" aria-pressed="false">Semua baris (<span id="n-all">0</span>)</button>
        </div>
        <span class="spacer"></span>
        <button type="button" class="btn btn-quiet btn-sm" id="add-row">+ Tambah baris</button>
      </div>

      <div id="rows-body">
        <?php foreach ($rows as $key => $r): echo render_review_row((string)$key, is_array($r) ? $r : [], $rowErrors[$key] ?? [], $ctx); endforeach; ?>
      </div>
      <div class="kcf-card" id="empty-cek" hidden><div class="kcf-empty"><?= icon('check', 22) ?><div class="fw-bold mt-1">Tidak ada baris yang perlu dicek.</div><div class="small">Buka "Semua baris" untuk memastikan setiap baris cocok dengan foto.</div></div></div>

      <div class="kcf-savebar mt-3">
        <div id="save-status" class="small fw-bold" role="status" aria-live="polite"></div>
        <div class="d-flex flex-wrap gap-2">
          <button name="action" value="save" class="btn btn-quiet">Simpan draf</button>
          <button name="action" value="confirm" class="btn btn-brand" id="btn-confirm"><?= icon('check', 18) ?>Konfirmasi data</button>
        </div>
      </div>
      <p class="small muted mt-2 mb-0">Setelah dikonfirmasi, data masuk rekap dan tren. Jika ada yang salah, sesi bisa dibuka kembali dari halaman detail sesi.</p>
    </form>
  </div>
</div>

<template id="row-template"><?= render_review_row('__KEY__', [], [], ['roster' => [], 'sesi_id' => $sesiId, 'form' => $form]) ?></template>

<?php if ($hasPhoto): ?>
<dialog class="kcf-dialog" id="photo-dialog" aria-label="Foto sumber">
  <div class="dlg-head">
    <strong>Foto sumber</strong>
    <span class="d-flex gap-2 align-items-center">
      <span class="btn-group btn-group-sm" role="group" aria-label="Zoom foto">
        <button type="button" class="btn btn-quiet js-zoom" data-dir="-1" aria-label="Perkecil">−</button>
        <button type="button" class="btn btn-quiet js-zoom" data-dir="0" aria-label="Ukuran asli"><span class="js-zoom-label">100%</span></button>
        <button type="button" class="btn btn-quiet js-zoom" data-dir="1" aria-label="Perbesar">+</button>
      </span>
      <button type="button" class="btn btn-brand btn-sm" id="close-photo">Tutup</button>
    </span>
  </div>
  <div class="kcf-photo" style="max-height:78vh;border-radius:0"><img class="js-photo-img" src="photo.php?id=<?= $sesiId ?>" alt="Foto tabel sumber" style="width:100%" loading="lazy"></div>
</dialog>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
