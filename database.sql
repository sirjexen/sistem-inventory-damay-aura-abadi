-- ============================================================================
-- Sistem Inventory — Damay Aura Abadi
-- Skema database MySQL (phpMyAdmin / XAMPP / cPanel)
-- Sumber: ERD 10 tabel (screenshot phpMyAdmin) + dokumen Proses Bisnis/WBS
-- Tim: Daniel Hutabarat (2412500684), Fernando Fiorentino (2412501476),
--      Daffa Ferdiansyah (2411500768) — Univ. Budi Luhur, 2026
-- Catatan keputusan: peran hanya Inventory, Produksi, Owner.
-- Peran Keuangan DIHAPUS (hanya Owner yang menyetujui pengajuan:
-- id_penyetuju selalu pengguna dengan peran Owner).
-- TIDAK ADA tabel Retur Barang, Pesanan Pelanggan, Detail Pesanan,
-- atau Pengeluaran Barang Jadi — tabel-tabel itu tidak ada di ERD.
-- Data INSERT di bawah ini HANYA CONTOH (DEMO) untuk pengujian,
-- bukan data asli perusahaan.
-- Cara pakai: buka phpMyAdmin -> BUKA database tujuan yang sudah dibuat
-- (mis. if0_43330214_inventory) -> pilih tab Import -> pilih file ini -> Go.
-- File ini TIDAK membuat database baru (tidak ada CREATE DATABASE / USE),
-- supaya bisa di-import ke database hosting apa pun namanya.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- pengguna
-- ----------------------------------------------------------------------------
CREATE TABLE pengguna (
  id_pengguna INT AUTO_INCREMENT PRIMARY KEY,
  nama VARCHAR(100) NOT NULL,
  peran ENUM('Inventory','Produksi','Owner') NOT NULL,
  telepon VARCHAR(20),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------------------------------------------
-- supplier
-- ----------------------------------------------------------------------------
CREATE TABLE supplier (
  id_supplier INT AUTO_INCREMENT PRIMARY KEY,
  nama_supplier VARCHAR(100) NOT NULL,
  kontak VARCHAR(50),
  alamat TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------------------------------------------
-- bahan_baku
-- ----------------------------------------------------------------------------
CREATE TABLE bahan_baku (
  id_bahan_baku INT AUTO_INCREMENT PRIMARY KEY,
  nama_bahan_baku VARCHAR(100) NOT NULL,
  satuan VARCHAR(20),
  stok_saat_ini DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  stok_minimal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ----------------------------------------------------------------------------
-- barang_jadi
-- ----------------------------------------------------------------------------
CREATE TABLE barang_jadi (
  id_barang_jadi INT AUTO_INCREMENT PRIMARY KEY,
  nama_barang_jadi VARCHAR(100) NOT NULL,
  satuan VARCHAR(20),
  stok_saat_ini INT NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ----------------------------------------------------------------------------
-- pengajuan_pengadaan
-- ----------------------------------------------------------------------------
CREATE TABLE pengajuan_pengadaan (
  id_pengajuan INT AUTO_INCREMENT PRIMARY KEY,
  id_pengaju INT NOT NULL,
  id_penyetuju INT,
  tanggal_pengajuan DATE NOT NULL,
  status_pengajuan ENUM('Menunggu','Disetujui','Ditolak') NOT NULL DEFAULT 'Menunggu',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_pengaju) REFERENCES pengguna (id_pengguna),
  FOREIGN KEY (id_penyetuju) REFERENCES pengguna (id_pengguna)
);

-- ----------------------------------------------------------------------------
-- detail_pengajuan
-- ----------------------------------------------------------------------------
CREATE TABLE detail_pengajuan (
  id_detail_pengajuan INT AUTO_INCREMENT PRIMARY KEY,
  id_pengajuan INT NOT NULL,
  id_bahan_baku INT NOT NULL,
  jumlah DECIMAL(12,2) NOT NULL,
  FOREIGN KEY (id_pengajuan) REFERENCES pengajuan_pengadaan (id_pengajuan),
  FOREIGN KEY (id_bahan_baku) REFERENCES bahan_baku (id_bahan_baku)
);

-- ----------------------------------------------------------------------------
-- pemesanan_pembelian (PO)
-- ----------------------------------------------------------------------------
CREATE TABLE pemesanan_pembelian (
  id_po INT AUTO_INCREMENT PRIMARY KEY,
  id_pengajuan INT NOT NULL,
  id_supplier INT NOT NULL,
  id_karyawan_inventory INT NOT NULL,
  no_nota_supplier VARCHAR(50),
  tanggal_terima DATETIME,
  status_po ENUM('Diproses','Diterima','Dibatalkan') NOT NULL DEFAULT 'Diproses',
  status_pembayaran ENUM('Belum Bayar','Lunas') NOT NULL DEFAULT 'Belum Bayar',
  catatan_pemeriksaan TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_pengajuan) REFERENCES pengajuan_pengadaan (id_pengajuan),
  FOREIGN KEY (id_supplier) REFERENCES supplier (id_supplier),
  FOREIGN KEY (id_karyawan_inventory) REFERENCES pengguna (id_pengguna)
);

-- ----------------------------------------------------------------------------
-- detail_pemesanan_pembelian
-- ----------------------------------------------------------------------------
CREATE TABLE detail_pemesanan_pembelian (
  id_detail_po INT AUTO_INCREMENT PRIMARY KEY,
  id_po INT NOT NULL,
  id_bahan_baku INT NOT NULL,
  jumlah_dipesan DECIMAL(12,2) NOT NULL,
  jumlah_diterima DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  harga_satuan DECIMAL(12,2) NOT NULL,
  kondisi ENUM('Sesuai','Rusak','Kurang') NOT NULL DEFAULT 'Sesuai',
  FOREIGN KEY (id_po) REFERENCES pemesanan_pembelian (id_po),
  FOREIGN KEY (id_bahan_baku) REFERENCES bahan_baku (id_bahan_baku)
);

-- ----------------------------------------------------------------------------
-- pengeluaran_bahan_baku (produksi per batch)
-- ----------------------------------------------------------------------------
CREATE TABLE pengeluaran_bahan_baku (
  id_pengeluaran INT AUTO_INCREMENT PRIMARY KEY,
  no_batch_produksi VARCHAR(50) NOT NULL,
  id_barang_jadi INT NOT NULL,
  jumlah_target_produksi INT NOT NULL,
  id_karyawan_produksi INT NOT NULL,
  id_karyawan_inventory INT NOT NULL,
  tanggal_keluar DATETIME,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_barang_jadi) REFERENCES barang_jadi (id_barang_jadi),
  FOREIGN KEY (id_karyawan_produksi) REFERENCES pengguna (id_pengguna),
  FOREIGN KEY (id_karyawan_inventory) REFERENCES pengguna (id_pengguna)
);

-- ----------------------------------------------------------------------------
-- detail_pengeluaran_bahan_baku
-- ----------------------------------------------------------------------------
CREATE TABLE detail_pengeluaran_bahan_baku (
  id_detail_pengeluaran INT AUTO_INCREMENT PRIMARY KEY,
  id_pengeluaran INT NOT NULL,
  id_bahan_baku INT NOT NULL,
  jumlah DECIMAL(12,2) NOT NULL,
  harga_satuan_bahan DECIMAL(12,2) NOT NULL,
  FOREIGN KEY (id_pengeluaran) REFERENCES pengeluaran_bahan_baku (id_pengeluaran),
  FOREIGN KEY (id_bahan_baku) REFERENCES bahan_baku (id_bahan_baku)
);

-- ============================================================================
-- DATA CONTOH (DEMO) — hanya untuk pengujian, bukan data asli perusahaan
-- ============================================================================

INSERT INTO pengguna (id_pengguna, nama, peran, telepon) VALUES
(1, 'Rina', 'Inventory', '081200000001'),
(2, 'Budi', 'Produksi', '081200000002'),
(3, 'Sari', 'Inventory', '081200000003'),
(4, 'Damay Saputra', 'Owner', '081200000004');

INSERT INTO supplier (id_supplier, nama_supplier, kontak, alamat) VALUES
(1, 'PT Kain Jaya Tekstil', '021-5550101', 'Tangerang'),
(2, 'CV Dakron Nusantara', '021-5550102', 'Tangerang');

INSERT INTO bahan_baku (id_bahan_baku, nama_bahan_baku, satuan, stok_saat_ini, stok_minimal) VALUES
(1, 'Kain Katun Premium', 'Meter', 120.00, 150.00),
(2, 'Dakron Silikon', 'Kg', 85.00, 100.00),
(3, 'Benang Jahit', 'Gulung', 40.00, 20.00),
(4, 'Resleting Bantal', 'Buah', 20.00, 50.00),
(5, 'Label Merek', 'Buah', 800.00, 300.00),
(6, 'Plastik Kemasan', 'Buah', 450.00, 200.00);

INSERT INTO barang_jadi (id_barang_jadi, nama_barang_jadi, satuan, stok_saat_ini) VALUES
(1, 'Bantal Standar', 'Buah', 120),
(2, 'Bantal Guling', 'Buah', 60),
(3, 'Bantal Terapi', 'Buah', 45);

INSERT INTO pengajuan_pengadaan (id_pengajuan, id_pengaju, id_penyetuju, tanggal_pengajuan, status_pengajuan) VALUES
(1, 1, 4, '2026-11-08', 'Disetujui'),
(2, 1, NULL, '2026-11-08', 'Menunggu');

INSERT INTO detail_pengajuan (id_detail_pengajuan, id_pengajuan, id_bahan_baku, jumlah) VALUES
(1, 1, 1, 50.00),
(2, 1, 4, 30.00),
(3, 2, 2, 100.00);

INSERT INTO pemesanan_pembelian (id_po, id_pengajuan, id_supplier, id_karyawan_inventory, no_nota_supplier, tanggal_terima, status_po, status_pembayaran, catatan_pemeriksaan) VALUES
(1, 1, 1, 1, 'NOTA-001', '2026-11-08 10:00:00', 'Diterima', 'Lunas', 'Kondisi bahan sesuai, jumlah lengkap.');

INSERT INTO detail_pemesanan_pembelian (id_detail_po, id_po, id_bahan_baku, jumlah_dipesan, jumlah_diterima, harga_satuan, kondisi) VALUES
(1, 1, 1, 50.00, 50.00, 15000.00, 'Sesuai'),
(2, 1, 4, 30.00, 30.00, 2500.00, 'Sesuai');

INSERT INTO pengeluaran_bahan_baku (id_pengeluaran, no_batch_produksi, id_barang_jadi, jumlah_target_produksi, id_karyawan_produksi, id_karyawan_inventory, tanggal_keluar) VALUES
(1, 'BATCH-001', 1, 20, 2, 1, '2026-11-08 14:00:00');

INSERT INTO detail_pengeluaran_bahan_baku (id_detail_pengeluaran, id_pengeluaran, id_bahan_baku, jumlah, harga_satuan_bahan) VALUES
(1, 1, 1, 10.00, 15000.00),
(2, 1, 2, 5.00, 20000.00);
