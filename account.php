<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$currentPosyandu = $p = require_login();
$pdo = db();

$newAccess = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    // Ganti kode akses: kode lama langsung tidak berlaku.
    $newAccess = generate_access_code();
    $pdo->prepare('UPDATE posyandu SET credential_hash = ?, code_rotated_at = NOW() WHERE id = ?')
        ->execute([hash_access_code($newAccess), $p['id']]);
}

$st = $pdo->prepare('SELECT created_at, code_rotated_at FROM posyandu WHERE id = ?');
$st->execute([$p['id']]);
$meta = $st->fetch();

$pageTitle = 'Lainnya';
require __DIR__ . '/includes/header.php';
?>
<h1 class="page-title h4 mb-3">Lainnya</h1>

<div class="kcf-card mb-3">
  <a class="d-flex align-items-center justify-content-between p-3 text-decoration-none text-reset border-bottom" href="anak.php"><span class="d-flex align-items-center gap-2 fw-bold"><?= icon('users', 20) ?>Daftar anak</span><?= icon('chevron', 18) ?></a>
  <a class="d-flex align-items-center justify-content-between p-3 text-decoration-none text-reset" href="history.php"><span class="d-flex align-items-center gap-2 fw-bold"><?= icon('list', 20) ?>Riwayat sesi</span><?= icon('chevron', 18) ?></a>
</div>

<div class="kcf-card mb-3"><div class="kcf-card-body">
  <h2 class="h6 mb-3">Akun Posyandu</h2>
  <dl class="kcf-kv">
    <dt>Nama</dt><dd><?= e($p['name']) ?></dd>
    <dt>ID Posyandu</dt><dd><code><?= e($p['posyandu_code']) ?></code></dd>
    <dt>Terdaftar</dt><dd><?= format_datetime($meta['created_at']) ?></dd>
    <dt>Kode diganti</dt><dd><?= $meta['code_rotated_at'] ? format_datetime($meta['code_rotated_at']) : 'belum pernah' ?></dd>
  </dl>
</div></div>

<?php if ($newAccess): ?>
  <div class="kcf-banner is-draft">
    <?= icon('key', 20) ?>
    <div class="flex-grow-1">
      <strong>Kode akses baru (hanya ditampilkan sekali):</strong>
      <div class="my-2"><?= code_box($newAccess, 'kode akses baru') ?></div>
      Kode lama sudah tidak berlaku. Bagikan kode baru hanya kepada kader yang berwenang.
    </div>
  </div>
<?php endif; ?>

<div class="kcf-card"><div class="kcf-card-body">
  <h2 class="h6">Ganti kode akses</h2>
  <p class="small muted">Gunakan jika kode bocor, atau ada kader yang tidak lagi bertugas. Kode dipakai bersama, jadi sistem tidak dapat membedakan siapa yang melakukan perubahan.</p>
  <form method="post" onsubmit="return confirm('Kode akses lama akan langsung tidak berlaku. Lanjutkan?');">
    <?= csrf_field() ?>
    <button class="btn btn-danger-quiet"><?= icon('key', 18) ?>Buat kode akses baru</button>
  </form>
</div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
