#!/usr/bin/env python3
"""
Uji end-to-end pengambilan foto di browser sungguhan (Playwright + Chromium dengan kamera palsu).
  pip install playwright && playwright install chromium
  php -S 127.0.0.1:8080 -t .   (database sudah dibuat)
  python3 tests/e2e_camera.py http://127.0.0.1:8080 PSY-XXXXXXXX KODE-AKSES-XXXX [folder_screenshot]
Jika CDN Bootstrap/font tidak terjangkau, set KCF_OFFLINE_ASSETS ke folder berisi bootstrap/dist dan file font (lihat README tests).
"""
import os, sys, base64, tempfile
from playwright.sync_api import sync_playwright

BASE, CODE, ACCESS = sys.argv[1].rstrip('/'), sys.argv[2], sys.argv[3]
OUT = sys.argv[4] if len(sys.argv) > 4 else None
if OUT:
    os.makedirs(OUT, exist_ok=True)
os.environ.setdefault('PLAYWRIGHT_BROWSERS_PATH', os.environ.get('PLAYWRIGHT_BROWSERS_PATH', '/opt/pw-browsers'))
ok = fail = 0
PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')


def check(label, cond, detail=''):
    global ok, fail
    if cond:
        ok += 1; print('  ok   ' + label)
    else:
        fail += 1; print('  GAGAL ' + label + ('  -> ' + str(detail) if detail else ''))


OFF = os.environ.get('KCF_OFFLINE_ASSETS')
def route(r):
    u = r.request.url
    if OFF:
        if 'bootstrap.min.css' in u: return r.fulfill(path=OFF + '/bootstrap/css/bootstrap.min.css', content_type='text/css')
        if 'bootstrap.bundle.min.js' in u: return r.fulfill(path=OFF + '/bootstrap/js/bootstrap.bundle.min.js', content_type='application/javascript')
        if 'fonts.googleapis.com' in u or 'fonts.gstatic.com' in u: return r.abort()
    return r.continue_()


def shot(page, name):
    if OUT:
        page.screenshot(path=os.path.join(OUT, name + '.png'))


def login(page):
    page.goto(BASE + '/login.php')
    page.fill('#posyandu_code', CODE); page.fill('#access_code', ACCESS)
    page.click('button.btn-brand'); page.wait_for_url('**/dashboard.php')


def new_ctx(browser, **kw):
    ctx = browser.new_context(**kw)
    ctx.route('**/*', route)
    return ctx


with sync_playwright() as p:
    FAKE = ['--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream']
    b = p.chromium.launch(headless=True, args=FAKE)

    print('== Desktop: kamera langsung ==')
    ctx = new_ctx(b, viewport={'width': 1280, 'height': 860}, permissions=['camera'])
    page = ctx.new_page()
    errs = []
    page.on('pageerror', lambda e: errs.append(str(e)))
    login(page)
    page.goto(BASE + '/upload.php'); page.wait_for_load_state('networkidle')
    check('tombol Buka kamera dan Pilih dari galeri ada', page.locator('#btn-camera').is_visible() and page.locator('#btn-gallery').is_visible())
    check('tiga pilihan jenis formulir tampil', page.locator('input[name=form_type]').count() == 3)
    check('di desktop tombol kamera bawaan HP disembunyikan', page.locator('#btn-native').is_hidden())
    shot(page, 'upload-kosong')

    # kirim tanpa foto → pesan jelas
    page.click('#submit-btn')
    check('kirim tanpa foto ditolak dengan pesan jelas', 'Belum ada foto' in page.locator('#photo-status').inner_text())

    page.click('#btn-camera')
    page.wait_for_selector('#cam-dialog[open]')
    page.wait_for_function("document.getElementById('cam-video').videoWidth > 0", timeout=15000)
    vw = page.evaluate("document.getElementById('cam-video').videoWidth")
    check('kamera menyala: video benar-benar mengalir (lebar %d px)' % vw, vw > 0)
    check('aliran kamera aktif', page.evaluate("document.getElementById('cam-video').srcObject !== null && document.getElementById('cam-video').srcObject.getTracks().some(t => t.readyState === 'live')"))
    page.wait_for_function("document.getElementById('cam-chips').innerText.trim().length > 0", timeout=8000)
    check('saran cahaya dan fokus langsung muncul', len(page.locator('#cam-chips').inner_text().strip()) > 0, page.locator('#cam-chips').inner_text())
    check('bingkai panduan tampil', page.locator('#cam-guide').is_visible())
    shot(page, 'kamera-langsung')
    page.click('#cam-shutter')
    page.wait_for_selector('#cam-shot:not([hidden])')
    check('hasil foto ditampilkan untuk ditinjau', page.locator('#cam-shot').is_visible() and page.locator('#cam-use').is_visible() and page.locator('#cam-retake').is_visible())
    shot(page, 'kamera-hasil')
    page.click('#cam-retake')
    check('Ulangi kembali ke tampilan kamera', page.locator('#cam-shutter').is_visible() and page.locator('#cam-shot').is_hidden())
    page.click('#cam-shutter'); page.wait_for_selector('#cam-shot:not([hidden])')
    page.click('#cam-use')
    page.wait_for_selector('#cam-dialog:not([open])', state='attached')
    info = page.evaluate("(() => { const f = document.getElementById('photo').files[0]; return f ? {n: f.name, t: f.type, s: f.size} : null })()")
    check('foto dari kamera masuk ke form sebagai berkas JPEG', info and info['t'] == 'image/jpeg' and info['s'] > 500 and info['n'].startswith('foto-register-'), info)
    page.wait_for_function("document.getElementById('photo-status').innerText.includes('Foto siap')", timeout=8000)
    check('pratinjau tampil dan status "Foto siap"', page.locator('#preview').is_visible())
    check('aliran kamera dimatikan setelah dipakai', page.evaluate("document.getElementById('cam-video').srcObject === null"))
    check('tombol berganti teks setelah ada foto', 'Foto ulang' in page.locator('#btn-camera').inner_text())
    shot(page, 'upload-berisi-foto')

    # kirim sampai halaman periksa (mode demo)
    if page.locator('#mode option[value=mock]').count():
        page.select_option('#mode', 'mock')
    page.click('#submit-btn')
    page.wait_for_url('**/review.php?id=*', timeout=60000)
    check('foto dari kamera berhasil dikirim sampai halaman Periksa & koreksi', 'Periksa' in page.content())

    print('== Esc menutup kamera dan mematikan aliran ==')
    page.goto(BASE + '/upload.php'); page.wait_for_load_state('networkidle')
    page.click('#btn-camera'); page.wait_for_function("document.getElementById('cam-video').videoWidth > 0", timeout=15000)
    page.keyboard.press('Escape')
    page.wait_for_selector('#cam-dialog:not([open])', state='attached')
    check('Esc menutup dialog', page.evaluate("!document.getElementById('cam-dialog').open"))
    check('Esc mematikan semua track kamera', page.evaluate("document.getElementById('cam-video').srcObject === null"))

    print('== Galeri (berkas) ==')
    tmp = tempfile.NamedTemporaryFile(suffix='.png', delete=False); tmp.write(PNG); tmp.close()
    page.set_input_files('#photo', tmp.name)
    page.wait_for_function("document.getElementById('photo-status').innerText.includes('Foto siap')", timeout=8000)
    check('pilih dari galeri tetap bekerja', page.locator('#preview').is_visible())
    with page.expect_file_chooser() as fc:
        page.click('#btn-gallery')
    check('tombol galeri membuka pemilih berkas', fc.value is not None)
    check('tidak ada galat JavaScript di halaman unggah', errs == [], errs)
    ctx.close()

    print('== HP (layar sentuh) ==')
    ctx = new_ctx(b, viewport={'width': 390, 'height': 844}, is_mobile=True, has_touch=True, permissions=['camera'], device_scale_factor=2)
    page = ctx.new_page(); login(page)
    page.goto(BASE + '/upload.php'); page.wait_for_load_state('networkidle')
    check('di HP tersedia kamera bawaan HP sebagai cadangan', page.locator('#btn-native').is_visible())
    check('input kamera bawaan memakai capture=environment', page.get_attribute('#photo-native', 'capture') == 'environment')
    shot(page, 'm-upload')
    with page.expect_file_chooser() as fc:
        page.click('#btn-native')
    tmp2 = tempfile.NamedTemporaryFile(suffix='.png', delete=False); tmp2.write(PNG); tmp2.close()
    fc.value.set_files(tmp2.name)
    page.wait_for_function("document.getElementById('photo').files.length === 1", timeout=8000)
    check('foto dari kamera bawaan HP masuk ke form', page.evaluate("document.getElementById('photo').files[0].type") == 'image/png')
    page.click('#btn-camera'); page.wait_for_function("document.getElementById('cam-video').videoWidth > 0", timeout=15000)
    box = page.locator('#cam-dialog').bounding_box()
    check('dialog kamera memenuhi layar HP', box and box['width'] >= 389 and box['height'] >= 800, box)
    check('tombol rana cukup besar untuk jempol (>=64px)', page.locator('#cam-shutter').bounding_box()['width'] >= 64)
    shot(page, 'm-kamera')
    ctx.close()

    print('== Galat kamera: pesan sesuai jenis galat ==')
    for err, expect in [('NotAllowedError', 'Izin kamera ditolak'), ('NotFoundError', 'tidak ditemukan'), ('NotReadableError', 'sedang dipakai'), ('NotSupportedError', 'izin belum diberikan')]:
        ctx = new_ctx(b, viewport={'width': 1280, 'height': 860}, permissions=['camera'])
        ctx.add_init_script("navigator.mediaDevices.getUserMedia = () => Promise.reject(new DOMException('uji', '%s'));" % err)
        page = ctx.new_page(); login(page)
        page.goto(BASE + '/upload.php'); page.wait_for_load_state('networkidle')
        page.click('#btn-camera'); page.wait_for_selector('#cam-msg:not([hidden])', timeout=10000)
        t = page.locator('#cam-msg').inner_text()
        check('%s: pesan "%s" dan jalan keluar ke galeri' % (err, expect), expect in t and 'galeri' in t, t)
        if err == 'NotAllowedError':
            check('galat: tombol rana mati dan tombol Batal masih ada', page.locator('#cam-shutter').is_disabled() and page.locator('#cam-close').is_visible())
            shot(page, 'kamera-ditolak')
            page.click('#cam-close')
            check('galeri tetap bisa dipakai setelah kamera gagal', page.locator('#btn-gallery').is_enabled())
        ctx.close()

    print('== Izin ditolak sungguhan (tanpa auto-izin) ==')
    b2 = p.chromium.launch(headless=True, args=['--use-fake-device-for-media-stream'])
    ctx = new_ctx(b2, viewport={'width': 1280, 'height': 860})
    page = ctx.new_page(); login(page)
    page.goto(BASE + '/upload.php'); page.wait_for_load_state('networkidle')
    page.click('#btn-camera'); page.wait_for_selector('#cam-msg:not([hidden])', timeout=15000)
    t = page.locator('#cam-msg').inner_text()
    check('tanpa izin: pesan informatif dengan jalan keluar', 'galeri' in t and 'izin' in t.lower(), t)
    ctx.close(); b2.close()

    print('== Konteks tidak aman (HTTP biasa di jaringan) ==')
    ctx = new_ctx(b, viewport={'width': 1280, 'height': 860}, permissions=['camera'])
    ctx.add_init_script("Object.defineProperty(window, 'isSecureContext', {get: () => false});")
    page = ctx.new_page(); login(page)
    page.goto(BASE + '/upload.php'); page.wait_for_load_state('networkidle')
    check('kamera bawaan HP otomatis ditawarkan', page.locator('#btn-native').is_visible())
    page.click('#btn-camera')
    check('penjelasan HTTPS muncul, dialog tidak dibuka', 'HTTPS' in page.locator('#photo-status').inner_text() and page.evaluate("!document.getElementById('cam-dialog').open"))
    ctx.close()
    b.close()

print('\n----------------------------------------')
print('Lulus: %d   Gagal: %d' % (ok, fail))
sys.exit(1 if fail else 0)
