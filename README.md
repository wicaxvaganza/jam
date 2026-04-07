# Bot Ngomong Jam + Sholat (PHP)

Aplikasi web PHP satu file untuk pengingat suara otomatis:
- pengumuman jam (`hourly`),
- pengumuman setengah jam + suara `kaget.mp3`,
- notifikasi presensi datang/pulang,
- notifikasi waktu salat Banyuwangi Kota.

UI berjalan di browser, sementara server PHP menangani:
- proxy TTS (Google Translate TTS),
- proxy jadwal salat (MyQuran),
- logging aktivitas dan error client.

## Fitur Utama

- Scheduler multi-event (jam, 30 menit, presensi, salat).
- Tombol `Start/Stop` agar autoplay audio di browser aktif aman.
- Monitoring event berikutnya + countdown real-time.
- Cache jadwal salat:
  - server cache harian di `cache_adzan/`,
  - fallback cache browser (`localStorage`) jika API gagal.
- Log harian dengan filter:
  - `TTS Activity` (`tts_activity.log`),
  - `Client Error` (`client_error.log`).
- Rate limit endpoint TTS per IP (`8 request/menit` default).
- Auto reload halaman dengan penghindaran bentrok event.

## Stack

- PHP (native, tanpa framework)
- JavaScript vanilla
- Tailwind CSS CDN
- cURL PHP extension
- API eksternal:
  - Google Translate TTS
  - MyQuran jadwal salat

## Struktur File

```text
jam/
|- index.php
|- cache_adzan/
|- belakang.mp3
|- depan.mp3
|- kaget.mp3
|- kaget1.mp3
|- tts_activity.log
|- client_error.log
|- .gitignore
```

## Cara Menjalankan (XAMPP)

1. Salin project ke:
   `C:\xampp\htdocs\jam`
2. Jalankan Apache dari XAMPP Control Panel.
3. Buka:
   `http://localhost/jam/`
4. Klik tombol `Start` sekali untuk mengaktifkan izin audio browser.

## Endpoint Internal (di `index.php`)

- `?text=...&tl=id`
  - Proxy TTS, output `audio/mpeg`.
- `?adzan=1`
  - Ambil jadwal salat Banyuwangi Kota (dengan cache harian server).
- `?viewlog=1&type=tts|client&daily=1`
  - Ambil log JSON untuk panel monitoring.
- `?clientlog=1&msg=...`
  - Simpan log error/info dari sisi browser.
- `?kagetlog=1&msg=...`
  - Simpan log event kaget ke `tts_activity.log`.

## Konfigurasi Cepat

Konstanta penting di bagian atas `index.php`:

- `$MAX_LOG_LINES` (default `50`)
- `$RATE_LIMIT_PER_MIN` (default `8`)
- `$MAX_LOG_LINE_LENGTH` (default `500`)
- Timezone: `Asia/Jakarta`

Lokasi jadwal salat saat ini hardcoded ke:
- Kota: `Banyuwangi`
- ID MyQuran: `1602`

## Catatan Produksi

- Pastikan ekstensi PHP `curl` aktif.
- Pastikan folder `cache_adzan` bisa ditulis oleh web server.
- Karena memakai sumber TTS publik, pertimbangkan retry/backoff dan pembatasan akses jika trafik tinggi.
- Untuk stabilitas autoplay, tab browser sebaiknya tetap aktif.

## Lisensi

Belum ditentukan. Tambahkan `LICENSE` sesuai kebutuhan sebelum dipublikasikan.
