<?php
// ============================================================================
// config.php — Kredensial koneksi database MySQL
// Sistem Inventory — Damay Aura Abadi
// ============================================================================
// CARA PAKAI (hosting cPanel):
//   1. Buat database MySQL di cPanel (mis. Databases > MySQL Databases).
//   2. Import file database.sql (di folder induk repo) lewat phpMyAdmin.
//   3. Buat user MySQL dan beri akses ke database tersebut.
//   4. Isi keempat konstanta di bawah dengan nilai dari hosting.
// JANGAN commit file ini ke repositori publik bila sudah berisi kredensial
// asli. Selama belum diisi, api.php akan menolak dengan pesan yang jelas.
// ============================================================================

define('DB_HOST', 'localhost');   // Host database (di cPanel biasanya 'localhost')
define('DB_NAME', 'inventory_damay_aura_abadi'); // Nama database MySQL
define('DB_USER', '');            // TODO: isi username MySQL dari hosting
define('DB_PASS', '');            // TODO: isi password MySQL dari hosting
