# Kerangka pitch deck (12 slide)

1. Judul dan satu kalimat: "Kader tidak perlu belajar sistem baru. Sistemnya yang belajar membaca tulisan kader."
2. Masalah: penyalinan berulang buku register, rekap manual, aplikasi pengganti kertas sulit bertahan (cantumkan sumber yang sudah kamu verifikasi).
3. Solusi: foto register → draf → periksa yang ragu → tren dan rekap.
4. Demo: 3 tangkapan layar (Periksa & koreksi, Perlu perhatian, Detail anak).
5. Kenapa bukan OCR biasa: penanda per sel, selisih dari catatan sebelumnya, saran nama, tren.
6. Arsitektur: foto → Gemini → normalisasi → validasi → review manusia → data terkonfirmasi → tren/rekap/ekspor.
7. Hasil evaluasi: tabel dari `eval/report.md` (akurasi per kolom, kesalahan diam-diam, beban review) dan waktu per halaman vs mengetik manual.
8. Integritas data: hasil OCR asli vs koreksi, buka kembali sesi, jejak aktivitas.
9. Dampak dan kelayakan: yang diuntungkan, jalur adopsi lewat Puskesmas/Dinkes; sebutkan hasil wawancara hanya jika benar-benar dilakukan.
10. Etika dan privasi: data sintetis, persetujuan sebelum foto ke AI, bukan diagnosis, status gizi belum dihitung, retensi foto.
11. **Penggunaan AI (wajib):** Gemini = membaca foto; asisten pengkodean = bantu menulis kode (ditinjau tim); hitungan tren dan validasi = kode deterministik.
12. Roadmap: crop foto per sel, z-score dengan tabel terverifikasi, akun per orang, integrasi sistem Puskesmas.
