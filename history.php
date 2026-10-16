<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$currentPosyandu = $p = require_login();
$pdo = db();
$pid = (int)$p['id'];

$statuses = ['uploaded', 'processing', 'review', 'confirmed', 'failed'];
$fStatus = (string)($_GET['status'] ?? '');
$fMonth = (string)($_GET['bulan'] ?? '');
if (!in_array($fStatus, $statuses, true)) {
    $fStatus = '';
}
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $fMonth)) {
    $fMonth = '';
}

$sql = 'SELECT s.id, s.activity_date, s.created_at, s.form_type, s.status, s.ocr_engine,
               (SELECT COUNT(*) FROM pengukuran m WHERE m.sesi_foto_id = s.id) AS row_count
        FROM sesi_foto s WHERE s.posyandu_id = ?';
$params = [$pid];
if ($fStatus !== '') {
    $sql .= ' AND s.status = ?';
    $params[] = $fStatus;
}
if ($fMonth !== '') {
    // filter bulan: tanggal kegiatan jika ada, jika tidak tanggal unggah
    $sql .= " AND DATE_FORMAT(COALESCE(s.activity_date, s.created_at), '%Y-%m') = ?";
    $params[] = $fMonth;
}
$sql .= ' ORDER BY s.created_at DESC, s.id DESC LIMIT 200';
$st = $pdo->prepare($sql);
$st->execute($params);
$sessions = $st->fetchAll();

$pageTitle = 'Riwayat sesi';
require __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
  <div>
    <h1 class="page-title h4 mb-1">Riwayat sesi</h1>
    <p class="muted mb-0">Semua foto register yang pernah diunggah.</p>
  </div>
  <a href="upload.php" class="btn btn-brand"><?= icon('camera', 18) ?>Foto halaman register</a>
</div>

<form method="get" class="row g-2 mb-3 align-items-end">
  <div class="col-sm-4 col-md-3">
    <label class="form-label" for="bulan">Bulan</label>
    <input type="month" class="form-control" id="bulan" name="bulan" value="<?= e($fMonth) ?>">
  </div>
  <div class="col-sm-4 col-md-3">
    <label class="form-label" for="status">Status</label>
    <select class="form-select" id="status" name="status">
      <option value="">Semua</option>
      <?php foreach ($statuses as $st_): [$l] = status_badge($st_); ?>
        <option value="<?= $st_ ?>" <?= $fStatus === $st_ ? 'selected' : '' ?>><?= e($l) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-sm-4 col-md-3 d-flex gap-2">
    <button class="btn btn-quiet">Terapkan</button>
    <a class="btn btn-link" href="history.php">Reset</a>
  </div>
</form>

<div class="kcf-card">
<?php if (!$sessions): ?>
  <div class="kcf-empty">Tidak ada sesi<?= ($fStatus || $fMonth) ? ' untuk filter ini' : '' ?>.</div>
<?php else: ?>
  <div class="kcf-scroll"><table class="kcf-ledger">
    <thead><tr><th>#</th><th>Tanggal kegiatan</th><th class="col-hide-sm">Diunggah</th><th class="col-hide-sm">Formulir</th><th class="num">Baris</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($sessions as $s): ?>
      <tr>
        <td><?= (int)$s['id'] ?></td>
        <td><?= format_date($s['activity_date']) ?></td>
        <td class="col-hide-sm"><?= format_datetime($s['created_at']) ?></td>
        <td class="col-hide-sm"><?= pill(form_def($s['form_type'])['short'], 'info') ?></td>
        <td class="num"><?= (int)$s['row_count'] ?></td>
        <td><?= status_pill($s['status']) ?><?php if (in_array($s['ocr_engine'], ['mock', 'demo'], true)): ?> <?= pill('demo', 'info') ?><?php endif; ?></td>
        <td class="text-end text-nowrap">
          <a class="btn btn-quiet btn-sm" href="session_detail.php?id=<?= (int)$s['id'] ?>">Detail</a>
          <?php if ($s['status'] !== 'confirmed'): ?>
            <a class="btn btn-brand btn-sm" href="<?= $s['status'] === 'failed' ? 'session_detail.php' : 'review.php' ?>?id=<?= (int)$s['id'] ?>"><?= $s['status'] === 'failed' ? 'Buka kembali' : 'Periksa' ?></a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
