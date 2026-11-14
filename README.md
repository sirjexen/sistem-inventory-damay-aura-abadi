# Sistem Inventory — Damay Aura Abadi

Prototipe UI/UX website Sistem Inventory untuk perusahaan Damay Aura Abadi
(Kesehatan & Alat Terapi; produksi bantal — Tangerang).

Tim: Daniel Hutabarat (2412500684), Fernando Fiorentino (2412501476),
Daffa Ferdiansyah (2411500768) — Univ. Budi Luhur, 2026.
Metode RAD, bentuk website PWA, deploy rencana hosting cPanel.

## Isi repo

- `index.html` — prototipe website yang bisa langsung dibuka dan diklik
  (demo, data contoh berlabel DEMO, tanpa backend).
- `database.sql` — skema database MySQL: 10 tabel sesuai ERD
  (screenshot phpMyAdmin) + data contoh untuk pengujian.

## Database

Import `database.sql` lewat phpMyAdmin (tab Import) atau:

```bash
mysql -u root -p < database.sql
```

Database: `inventory_damay_aura_abadi`, 10 tabel:
`pengguna`, `supplier`, `bahan_baku`, `barang_jadi`,
`pengajuan_pengadaan`, `detail_pengajuan`, `pemesanan_pembelian`,
`detail_pemesanan_pembelian`, `pengeluaran_bahan_baku`,
`detail_pengeluaran_bahan_baku`.

Tidak ada tabel Retur Barang, Pesanan Pelanggan, atau Pengeluaran
Barang Jadi — tabel-tabel itu tidak ada di ERD.

## Peran

Hanya 3 peran: **Inventory**, **Produksi**, **Owner**.
Peran Keuangan dihapus; hanya Owner yang menyetujui/menolak pengajuan
(`id_penyetuju` selalu Owner).

- Inventory: Dashboard, Bahan Baku, Pengajuan, Supplier, Pemesanan,
  Pengeluaran, Barang Jadi, Laporan.
- Produksi: Dashboard, Bahan Baku (lihat saja), Pengeluaran
  (batch produksi), Barang Jadi.
- Owner: Dashboard, Bahan Baku (lihat saja), Barang Jadi (lihat stok di
  gudang), Pengajuan (setujui/tolak), Pemesanan (lihat), Pengguna
  (tambah pengguna), Laporan, Tentang Proyek.

## Alur bisnis

Cek stok → ajukan pengadaan → disetujui Owner → pesan ke supplier →
terima & periksa bahan → simpan & catat stok → keluarkan bahan baku
untuk produksi (per batch — satu barang jadi bisa memakai banyak bahan
baku, satu baris detail per bahan) → simpan barang jadi.

## Catatan

GitHub Pages hanya menyajikan `index.html` sebagai front-end statis;
database di atas belum terhubung ke website (butuh backend/hosting
dengan MySQL, mis. cPanel, untuk pemakaian nyata).
