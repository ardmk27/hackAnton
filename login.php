<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$pdo = db();
$code = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $code = strtoupper(trim((string)($_POST['posyandu_code'] ?? '')));
    $code = substr($code, 0, 20);
    $access = normalize_access_code((string)($_POST['access_code'] ?? ''));

    if (login_blocked($pdo, $code)) {
        flash('danger', 'Terlalu banyak percobaan gagal. Tunggu sekitar 15 menit lalu coba lagi.');
    } else {
        $st = $pdo->prepare('SELECT id, credential_hash FROM posyandu WHERE posyandu_code = ?');
        $st->execute([$code]);
        $row = $st->fetch();
        // password_verify tetap dijalankan agar waktu respons tidak membocorkan ID yang valid
        $hash = $row['credential_hash'] ?? '$2y$10$0000000000000000000000.0000000000000000000000000000000';
        $ok = password_verify($access, $hash) && $row;
        if ($ok) {
            clear_failed_logins($pdo, $code);
            login_posyandu($row);
            redirect('dashboard.php');
        }
        record_failed_login($pdo, $code);
        flash('danger', 'ID Posyandu atau kode akses salah.');
    }
}

$pageTitle = 'Masuk';
require __DIR__ . '/includes/landing.php';
require __DIR__ . '/includes/header.php';
?>
<div class="kcf-landing">
  <div class="kcf-authcol">
    <div class="kcf-auth">
      <div class="brand-row d-lg-none"><?= brand_mark(40) ?><div><h1>Masuk ke Posyandu</h1><div class="muted small">Foto register jadi draf, tren, dan rekap.</div></div></div>
      <h1 class="d-none d-lg-block h3 mb-3">Masuk ke Posyandu</h1>
      <div class="kcf-card"><div class="kcf-card-body">
        <form method="post" autocomplete="off">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label" for="posyandu_code">ID Posyandu</label>
            <input class="form-control" id="posyandu_code" name="posyandu_code" required maxlength="20" value="<?= e($code) ?>" placeholder="PSY-XXXXXXXX" autocapitalize="characters">
          </div>
          <div class="mb-3">
            <label class="form-label" for="access_code">Kode akses</label>
            <input class="form-control" id="access_code" name="access_code" type="password" required maxlength="30" placeholder="XXXX-XXXX-XXXX">
          </div>
          <button class="btn btn-brand btn-lg w-100">Masuk</button>
        </form>
        <hr>
        <p class="mb-0 small">Belum terdaftar? <a class="fw-bold" href="register.php">Daftarkan Posyandu</a></p>
      </div></div>
    </div>
  </div>
  <?php render_landing_pitch(); ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
