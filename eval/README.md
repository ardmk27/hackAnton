# Evaluasi pembacaan foto

Tujuan: angka yang jujur untuk pitch deck: akurasi per kolom, **kesalahan diam-diam** (salah dan tidak ditandai), dan beban review.

## Menyiapkan data uji
1. Tulis tangan 20-30 halaman register **dengan data karangan** (bukan anak asli), oleh **minimal 5 orang berbeda**.
2. Foto dengan HP: sebagian normal, sebagian miring, bayangan, cahaya redup.
3. Isi kebenaran dasar **saat menulis**: salin `samples/TEMPLATE.csv.txt` menjadi `NAMA.csv`, foto menjadi `NAMA.jpg`.
4. Pisahkan: sekitar sepertiga untuk menyetel prompt/aturan, sisanya dikunci untuk uji akhir. Jangan menyetel pada data uji akhir.

## Menjalankan
```
php eval/run.php --mode=gemini --dir=eval/samples [--two-pass] --note="5 penulis, 24 halaman, data sintetis"
```
`--mode=mock` hanya menguji skrip dengan data contoh dan **bukan hasil membaca foto**.

## Mengukur waktu (manual)
Stopwatch untuk satu halaman: (a) mengetik manual ke spreadsheet; (b) foto + periksa baris ditandai + konfirmasi. Minimal 3 orang. Laporkan rata-rata dan sebutkan jumlah orangnya.

## Melaporkan
Tempel tabel dari `eval/report.md`. Laporkan juga kegagalan dan kondisi foto.
