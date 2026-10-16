<?php
/** @var string[] $extraScripts */
/** @var ?array $currentPosyandu */
/** @var int $attnCount */
/** @var callable $on */
/** @var callable $cur */
$currentPosyandu = $currentPosyandu ?? null;
?>
<p class="kcf-footnote">Hasil baca AI adalah <strong>draf</strong> yang harus diperiksa manusia. Aplikasi ini bukan pengganti buku fisik dan bukan alat diagnosis.</p>
</main>
<?php if ($currentPosyandu && isset($on)): ?>
<nav class="kcf-bottomnav" aria-label="Menu utama (HP)">
  <a class="<?= trim($on('home')) ?>" href="dashboard.php"<?= $cur('home') ?>><?= icon('book', 24) ?>Beranda</a>
  <a class="is-fab<?= $on('foto') ?>" href="upload.php"<?= $cur('foto') ?>><span class="fab"><?= icon('camera', 26) ?></span>Foto</a>
  <a class="<?= trim($on('attn')) ?>" href="attention.php"<?= $cur('attn') ?>><?= icon('alert', 24) ?>Perhatian<?php if (($attnCount ?? 0) > 0): ?><span class="kcf-count" aria-label="<?= (int)$attnCount ?> anak"><?= (int)$attnCount ?></span><?php endif; ?></a>
  <a class="<?= trim($on('rekap')) ?>" href="recap.php"<?= $cur('rekap') ?>><?= icon('bars', 24) ?>Rekap</a>
  <a class="<?= trim($on('anak', 'riwayat', 'akun')) ?>" href="account.php"<?= $cur('anak', 'riwayat', 'akun') ?>><?= icon('list', 24) ?>Lainnya</a>
</nav>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/copy.js?v=1"></script>
<?php foreach (($extraScripts ?? []) as $s): ?>
<script src="<?= e($s) ?>"></script>
<?php endforeach; ?>
</body>
</html>
