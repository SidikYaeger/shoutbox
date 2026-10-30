# Aplikasi Bersama Kelompok — Shoutbox (`kelompok_db`)

Repositori ini merupakan aplikasi bersama (**Shoutbox**) untuk **Tugas 1 Pra-UTS Cloud Computing**. Kode di dalam repositori ini dipasang pada folder `app/` di dalam direktori `personal/` masing-masing anggota kelompok dan dirancang siap dipindahkan ke **VM Compute Engine GCP** dengan Load Balancer Apache2 serta database terpusat setelah UTS.

---
.

## Daftar Anggota Kelompok & Route

| Route ID | Peran | NIM | Nama Mahasiswa |
| :--- | :--- | :--- | :--- |
| **`m0`** | Ketua | `103012400147` | Zhafir Muhammad Tectona Grandito |
| **`m1`** | Anggota 1 | `103012400188` | Shahrul Putra Wiguna |
| **`m2`** | Anggota 2 | `103012400032` | Akbar Fawwaz |
| **`m3`** | Anggota 3 | `103012400224` | Muhammad Adira Gumillar |
| **`m4`** | Anggota 4 | `103012400247` | Muhammad Fazil Ataurrahman |

---


## Fitur Aplikasi Shoutbox

1. **Tulis Pesan (`POST`)**: Pengunjung dapat mengirim pesan singkat yang divalidasi di sisi klien (JavaScript) dan sisi server (PHP) serta otomatis mencatat `route_id` server penerima (`MEMBER_ROUTE`).
2. **Baca & Auto-Refresh AJAX (`GET ?action=fetch`)**: Daftar pesan terbaru diambil dan diperbarui secara berkala setiap **4 detik** menggunakan `fetch()` tanpa memuat ulang halaman, lengkap dengan indikator `route_id` node yang melayani setiap polling AJAX.
3. **Pencarian Pesan (Live Search)**: Memfilter pesan berdasarkan nama pengirim, isi pesan, maupun `route_id` (`m0`–`m4`).
4. **Hapus Log Transmisi (Delete Log)**: Fitur menghapus rekaman pesan per-item maupun membersihkan seluruh log transmisi via AJAX tanpa reload halaman.
5. **Praktik Keamanan Dasar**:
   - Seluruh query SQL menggunakan **PDO Prepared Statements** (`PDO::ATTR_EMULATE_PREPARES => false`) sehingga kebal terhadap injeksi SQL (`' OR '1'='1`).
   - Seluruh output melewati `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` di sisi server dan `textContent` di sisi DOM klien sehingga kebal terhadap XSS (`<script>alert(1)</script>`).
   - Pemisahan penuh antara kode aplikasi (`app/`) dan kredensial lokal (`config/db.php` & `identity.php`).

---

## Struktur Folder Integrasi

```text
htdocs/personal/
├── index.php                ← Personal page (individu, diabaikan oleh git repo kelompok)
├── assets/                  ← CSS, JS, dan gambar personal page (individu)
├── identity.php             ← Identitas mahasiswa lokal (MEMBER_ROUTE, MEMBER_NAMA, MEMBER_NIM)
├── config/
│   ├── .htaccess            ← Require all denied
│   ├── db.php               ← Kredensial database lokal (tidak di-commit)
│   └── db.example.php       ← Contoh konfigurasi database
└── app/                     ← Hasil git clone repo kelompok ini
    ├── index.php            ← Halaman & endpoint AJAX aplikasi bersama Shoutbox
    ├── lib/db.php           ← Koneksi PDO
    ├── partials/served_by.php ← Banner "Anda dilayani oleh laman <nama> (<NIM>)"
    └── sql/schema.sql       ← Skema database kelompok_db (anggota & shout)
```

---

## Cara Instalasi di XAMPP / WAMP

1. **Siapkan Folder Personal**:
   Buat folder `C:\xampp\htdocs\personal\` (XAMPP) atau `C:\wamp64\www\personal\` (WAMP), lalu clone repositori ini sebagai folder `app`:
   ```bash
   git clone https://github.com/SidikYaeger/shoutbox.git app
   ```

2. **Impor Skema Database**:
   - Buka `http://localhost/phpmyadmin`.
   - Impor file `app/sql/schema.sql` (otomatis membuat database `kelompok_db`, tabel `anggota` berisi `m0`–`m4`, dan tabel `shout`).
   - Buat user database lokal dengan hak minimum melalui tab SQL:
     ```sql
     CREATE USER IF NOT EXISTS 'app_local'@'localhost' IDENTIFIED BY 'password_lokal';
     CREATE USER IF NOT EXISTS 'app_local'@'127.0.0.1' IDENTIFIED BY 'password_lokal';
     GRANT SELECT, INSERT, UPDATE, DELETE ON kelompok_db.* TO 'app_local'@'localhost';
     GRANT SELECT, INSERT, UPDATE, DELETE ON kelompok_db.* TO 'app_local'@'127.0.0.1';
     FLUSH PRIVILEGES;
     ```

3. **Buat File Konfigurasi Lokal (di luar `app/`)**:
   - Buat `identity.php` sesuai route masing-masing anggota (`m0` s.d. `m4`).
   - Salin `config/db.example.php` menjadi `config/db.php` dan sesuaikan kredensial.

4. **Jalankan & Uji**:
   - Buka `http://localhost/personal/` untuk halaman personal page individu.
   - Buka `http://localhost/personal/app/index.php` untuk aplikasi bersama Shoutbox.
