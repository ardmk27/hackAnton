<?php
declare(strict_types=1);

/** Petakan awalan pesan error validasi → nama field form (untuk menandai kolom yang bermasalah). */
function error_field_from_message(string $msg): ?string
{
    $map = [
        'Tanggal lahir' => 'tanggal_lahir', 'Periode pengukuran' => 'periode_pengukuran', 'BB' => 'bb_kg',
        'TB' => 'tb_pb_cm', 'Lingkar kepala' => 'lingkar_kepala_cm', 'LiLA' => 'lila_cm',
        'Usia kehamilan' => 'usia_kehamilan_minggu', 'Sistolik' => 'td_sistolik', 'Diastolik' => 'td_diastolik', 'Gula darah' => 'gula_darah_mgdl',
        'Nama' => 'nama_peserta', 'Baris tampak ganda' => 'nama_peserta',
    ];
    foreach ($map as $prefix => $field) {
        if (str_starts_with($msg, $prefix)) {
            return $field;
        }
    }
    return null;
}

/**
 * Satu kartu baris pada halaman periksa & koreksi.
 * $r memakai nama kolom DB (weight_kg, ...) atau nama form (bb_kg, ...); keduanya diterima.
 * $ctx: ['roster' => array (load_roster), 'sesi_id' => int, 'form' => kunci formulir]
 */
function render_review_row(string $key, array $r, array $errors = [], array $ctx = []): string
{
    $roster = $ctx['roster'] ?? [];
    $sesiId = (int)($ctx['sesi_id'] ?? 0);
    $form = form_key($ctx['form'] ?? null);
    $def = form_def($form);
    $subject = $def['subject'];
    $rules = form_measure_rules($form);

    $val = function (string $formKey, string $dbKey) use ($r): string {
        $v = $r[$formKey] ?? $r[$dbKey] ?? '';
        if ($v === null) {
            return '';
        }
        if (is_numeric($v) && $dbKey !== 'nomor_pendaftaran' && str_contains((string)$v, '.')) {
            $v = rtrim(rtrim((string)$v, '0'), '.');
        }
        return (string)$v;
    };
    $nomor = $val('nomor_pendaftaran', 'nomor_pendaftaran');
    $nama = $val('nama_peserta', 'nama_peserta');
    $dob = $val('tanggal_lahir', 'tanggal_lahir');
    $period = $val('periode_pengukuran', 'measurement_period');
    $sex = (string)($r['jenis_kelamin'] ?? '');
    $ket = $val('keterangan', 'keterangan');
    $vals = [];
    $dbVals = ['measurement_period' => $period];
    foreach ($rules as $k => $rule) {
        $vals[$k] = $val($k, $rule['col']);
        $dbVals[$rule['col']] = $vals[$k];
    }
    $needs = !empty($r['needs_review']);
    $checked = !empty($r['human_checked']);
    $reason = (string)($r['review_reason'] ?? '');
    $snapshot = (string)($r['ocr_snapshot'] ?? '');
    $isOcr = $snapshot !== '';

    // Identitas: dikenali persis → bandingkan dengan catatan sebelumnya; nama kembar → minta pilih; selain itu beri saran.
    $rowKey = ['nomor_pendaftaran' => $nomor !== '' ? $nomor : null, 'nama_peserta' => $nama !== '' ? $nama : null, 'tanggal_lahir' => $dob !== '' ? $dob : null];
    $amb = $roster ? name_ambiguity($roster, $rowKey) : ['status' => 'none', 'candidates' => []];
    $known = ($roster && $amb['status'] === 'known') ? find_exact_peserta($roster, $rowKey) : null;
    $prev = ($known && !empty($def['trend'])) ? previous_point($known, $period !== '' ? $period : null) : null;
    $suggest = ($roster && $amb['status'] === 'none') ? suggest_peserta($roster, $rowKey) : [];

    $warnList = row_warnings($dbVals, $prev, $form);
    $infoNotes = [];
    if ($amb['status'] === 'ambiguous') {
        $n = count($amb['candidates']);
        $hints = implode('; ', array_map(fn($p) => person_hint($p), $amb['candidates']));
        $warnList[] = ['field' => 'nama_peserta', 'msg' => $n >= 2
            ? "Ada $n $subject bernama $nama ($hints). Pilih yang benar di atas, atau isi no. daftar atau tanggal lahir agar tidak tertukar."
            : "Sudah ada 1 $subject bernama $nama ($hints). Pilih jika orangnya sama, atau isi no. daftar atau tanggal lahir jika berbeda."];
    } elseif ($amb['status'] === 'other_child') {
        $hints = implode('; ', array_map(fn($p) => person_hint($p), $amb['candidates']));
        $infoNotes[] = "Nama sama dengan $subject lain ($hints). Baris ini dihitung sebagai $subject berbeda karena no. daftar atau tanggal lahirnya lain.";
    }
    if ($known && $period !== '' && isset($known['points'][$period]) && (int)$known['points'][$period]['sesi_foto_id'] !== $sesiId) {
        $warnList[] = ['field' => 'periode_pengukuran', 'msg' => 'Sudah ada catatan ' . format_period($period) . ' untuk ' . $subject . ' ini dari sesi #' . (int)$known['points'][$period]['sesi_foto_id'] . '. Pastikan bukan unggahan ganda'];
    }

    $flagSet = [];
    foreach (explode(',', (string)($r['flag_fields'] ?? '')) as $f) {
        if (isset(FORM_FIELDS[trim($f)])) {
            $flagSet[trim($f)] = 'flag';
        }
    }
    foreach ($warnList as $w) {
        $flagSet[$w['field']] = 'flag';
    }
    $errSet = [];
    foreach ($errors as $er) {
        $f = error_field_from_message((string)$er);
        if ($f) {
            $errSet[$f] = true;
        }
    }
    $cls = function (string $field) use ($flagSet, $errSet, $checked): string {
        if (isset($errSet[$field])) {
            return ' is-flag-bad';
        }
        return (!$checked && isset($flagSet[$field])) ? ' is-flag' : '';
    };

    $isFlagged = !$checked && ($needs || $flagSet || $errors);
    $rowCls = ($isFlagged ? ' is-flagged' : '') . ($errors ? ' is-error' : '') . ($checked ? ' is-checked' : '');

    $n = fn(string $f) => 'rows[' . e($key) . '][' . $f . ']';
    $id = fn(string $f) => 'f-' . e($key) . '-' . $f;

    ob_start();
    ?>
    <div class="kcf-rowcard review-row<?= $rowCls ?>" data-ocr="<?= $isOcr ? '1' : '0' ?>">
      <div class="rc-head">
        <span class="rc-no row-no" aria-hidden="true"></span>
        <div class="flex-grow-1" style="min-width:0">
          <label class="visually-hidden" for="<?= $id('nama') ?>">Nama</label>
          <input class="form-control rc-name key-field<?= $cls('nama_peserta') ?>" id="<?= $id('nama') ?>" name="<?= $n('nama_peserta') ?>" maxlength="100" placeholder="Nama" value="<?= e($nama) ?>">
        </div>
        <button type="button" class="btn btn-danger-quiet btn-sm btn-del-row" aria-label="Hapus baris ini"><?= icon('trash', 18) ?></button>
      </div>

      <?php if ($known): ?>
        <div class="rc-suggest"><span class="known"><?= icon('check', 16) ?>Dikenali: <?= e($known['name']) ?></span>
          <?php if ($prev): ?><span class="muted">Catatan sebelumnya (<?= e(format_period($prev['period'])) ?>): <strong><?= e(show_num($prev['weight_kg'])) ?> kg</strong>, <strong><?= e(show_num($prev['height_cm'])) ?> cm</strong></span><?php endif; ?>
        </div>
      <?php elseif ($amb['status'] === 'ambiguous'): ?>
        <div class="rc-suggest rc-pick"><span class="muted"><?= count($amb['candidates']) >= 2 ? 'Nama kembar, pilih yang benar:' : 'Mungkin ' . $subject . ' ini:' ?></span>
          <?php foreach (array_slice($amb['candidates'], 0, 6) as $sg): $fill = ['nama_peserta' => $sg['name'], 'nomor_pendaftaran' => $sg['ref'] ?? '', 'tanggal_lahir' => $sg['dob'] ?? '', 'jenis_kelamin' => $sg['sex'] ?? '']; ?>
            <button type="button" class="btn-fill" data-fill="<?= e(json_encode($fill, JSON_UNESCAPED_UNICODE)) ?>"><?= e($sg['name']) ?>, <?= e(person_hint($sg)) ?></button>
          <?php endforeach; ?>
        </div>
      <?php elseif ($suggest): ?>
        <div class="rc-suggest"><span class="muted">Mungkin <?= e($subject) ?> ini:</span>
          <?php foreach ($suggest as $sg): $fill = ['nama_peserta' => $sg['name'], 'nomor_pendaftaran' => $sg['ref'] ?? '', 'tanggal_lahir' => $sg['dob'] ?? '', 'jenis_kelamin' => $sg['sex'] ?? '']; ?>
            <button type="button" class="btn-fill" data-fill="<?= e(json_encode($fill, JSON_UNESCAPED_UNICODE)) ?>"><?= e($sg['name']) ?><?= $sg['ref'] ? ' (no. ' . e($sg['ref']) . ')' : '' ?></button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <div class="rc-dupe small" hidden></div>

      <div class="rc-grid">
        <div><label class="rc-label" for="<?= $id('no') ?>">No. daftar</label><input class="form-control<?= $cls('nomor_pendaftaran') ?>" id="<?= $id('no') ?>" name="<?= $n('nomor_pendaftaran') ?>" maxlength="30" value="<?= e($nomor) ?>"></div>
        <div><label class="rc-label" for="<?= $id('dob') ?>">Tanggal lahir</label><input class="form-control<?= $cls('tanggal_lahir') ?>" type="date" id="<?= $id('dob') ?>" name="<?= $n('tanggal_lahir') ?>" value="<?= e($dob) ?>"></div>
        <?php if (form_shows($form, 'jenis_kelamin')): ?>
        <div><label class="rc-label" for="<?= $id('jk') ?>">Jenis kelamin</label>
          <select class="form-select<?= $cls('jenis_kelamin') ?>" id="<?= $id('jk') ?>" name="<?= $n('jenis_kelamin') ?>">
            <option value="">—</option><option value="L" <?= $sex === 'L' ? 'selected' : '' ?>>Laki-laki</option><option value="P" <?= $sex === 'P' ? 'selected' : '' ?>>Perempuan</option>
          </select></div>
        <?php endif; ?>
        <div><label class="rc-label" for="<?= $id('per') ?>">Periode (bulan di tabel)</label><input class="form-control key-field<?= $cls('periode_pengukuran') ?>" type="month" id="<?= $id('per') ?>" name="<?= $n('periode_pengukuran') ?>" value="<?= e($period) ?>"></div>
        <?php foreach ($rules as $k => $rule): $isKey = in_array($k, ['bb_kg', 'tb_pb_cm'], true); ?>
        <div><label class="rc-label" for="<?= $id($k) ?>"><?= e($rule['label']) ?><?= $rule['unit'] !== '' ? ' (' . e($rule['unit']) . ')' : '' ?></label>
          <input class="form-control<?= $isKey ? ' key-field' : '' ?><?= $cls($k) ?>" inputmode="<?= (int)$rule['dec'] === 0 ? 'numeric' : 'decimal' ?>" id="<?= $id($k) ?>" name="<?= $n($k) ?>" value="<?= e($vals[$k]) ?>"></div>
        <?php endforeach; ?>
        <div class="wide"><label class="rc-label" for="<?= $id('ket') ?>">Keterangan</label><input class="form-control" id="<?= $id('ket') ?>" name="<?= $n('keterangan') ?>" maxlength="255" value="<?= e($ket) ?>"></div>
      </div>

      <input type="hidden" name="<?= $n('review_reason') ?>" value="<?= e($reason) ?>">
      <input type="hidden" class="h-flags" name="<?= $n('flag_fields') ?>" value="<?= e((string)($r['flag_fields'] ?? '')) ?>">
      <input type="hidden" name="<?= $n('ocr_snapshot') ?>" value="<?= e($snapshot) ?>">
      <input type="hidden" class="h-checked" name="<?= $n('human_checked') ?>" value="<?= $checked ? '1' : '0' ?>">

      <?php if ($reason || $warnList || $errors || $infoNotes): ?>
      <ul class="rc-notes">
        <?php if ($reason): ?><li class="k"><?= icon('info', 16) ?><span>Catatan pembacaan: <?= e($reason) ?></span></li><?php endif; ?>
        <?php foreach ($infoNotes as $in): ?><li class="k"><?= icon('info', 16) ?><span><?= e($in) ?></span></li><?php endforeach; ?>
        <?php foreach ($warnList as $w): ?><li class="w"><?= icon('alert', 16) ?><span><?= e($w['msg']) ?></span></li><?php endforeach; ?>
        <?php foreach ($errors as $er): ?><li class="e"><?= icon('x', 16) ?><span><?= e($er) ?></span></li><?php endforeach; ?>
      </ul>
      <?php endif; ?>

      <div class="rc-actions">
        <label class="flagbox"><input class="form-check-input chk-needs" type="checkbox" value="1" name="<?= $n('needs_review') ?>" <?= $needs ? 'checked' : '' ?>> Tandai perlu diperiksa</label>
        <button type="button" class="btn btn-quiet btn-sm btn-mark-ok" aria-pressed="<?= $checked ? 'true' : 'false' ?>"><?= icon('check', 16) ?><span><?= $checked ? 'Sudah diperiksa (batalkan)' : 'Sudah saya periksa' ?></span></button>
      </div>
    </div>
    <?php
    return (string)ob_get_clean();
}
