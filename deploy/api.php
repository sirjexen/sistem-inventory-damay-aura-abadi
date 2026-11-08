<?php
/**
 * api.php — Satu endpoint JSON untuk Sistem Inventory Damay Aura Abadi.
 *
 * Menghubungkan prototipe front-end (index.html) ke database MySQL asli
 * (skema 10 tabel ERD, lihat database.sql di folder induk repo).
 *
 * CARA PAKAI:
 *   GET  api.php?action=init        -> seluruh data yang ditampilkan UI
 *   POST api.php?action=<aksi>      -> mutasi, body JSON (lihat daftar aksi)
 *
 * ATURAN KERAS (sesuai ERD 10 tabel — jangan dilanggar):
 *   - Nama tabel/kolom persis ERD (id_bahan_baku, nama_bahan_baku,
 *     stok_saat_ini, no_batch_produksi, id_penyetuju, status_pembayaran,
 *     dst.). Tidak ada tabel/fitur tambahan. TIDAK ADA Retur Barang,
 *     Pesanan Pelanggan, atau Pengeluaran Barang Jadi.
 *   - Hanya 3 peran: Inventory, Produksi, Owner. Peran Keuangan TIDAK ADA.
 *     Hanya Owner yang bisa menyetujui/menolak pengajuan (id_penyetuju
 *     selalu Owner) — ditegakkan di server, bukan cuma di UI.
 *   - Kolom status_pembayaran tetap tampil (itu kolom ERD).
 *   - harga_satuan di detail_pemesanan_pembelian adalah hasil NEGOSIASI
 *     dengan supplier — BUKAN harga paten. Tidak ada daftar harga bawaan
 *     di mana pun (backend maupun front-end): form Buat PO mengosongkan
 *     kolom harga dan API menyimpan persis nilai yang diketik, serta
 *     menolak bila harga tidak diisi.
 *   - Logika stok SAMA PERSIS dengan prototipe:
 *       * Terima PO        : stok_saat_ini bahan_baku += jumlah_diterima.
 *       * Pengeluaran batch: stok_saat_ini bahan_baku -= jumlah,
 *                            stok_saat_ini barang_jadi += jumlah_target_produksi.
 *       * Pengeluaran ditolak bila stok_saat_ini bahan_baku < jumlah.
 *
 * KEAMANAN (sesuai batas ERD — tabel pengguna TIDAK punya kolom password):
 *   Login di prototipe adalah pemilihan peran + nama, bukan autentikasi
 *   kata sandi. Setiap mutasi WAJIB menyertakan `role` dan `user_id` di
 *   body JSON. Server memverifikasi bahwa user_id ADA di tabel pengguna
 *   DAN perannya sama dengan `role` yang diklaim, lalu memeriksa apakah
 *   peran itu berhak menjalankan aksi tersebut. Jadi seseorang tidak bisa
 *   mengklaim peran Owner tanpa id_pengguna Owner yang ada di database.
 *   Ini bukan autentikasi penuh — bila aplikasi dipakai di produksi,
 *   tambahkan login kata sandi + session di atas API ini.
 *
 * Semua query memakai prepared statements (PDO). Mutasi multi-tabel
 * memakai transaksi agar stok dan dokumen selalu konsisten.
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/config.php';

// ---------------------------------------------------------------------------
// Utilitas respons
// ---------------------------------------------------------------------------
function fail(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function ok(array $data = []): void
{
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------------------
// Koneksi database
// ---------------------------------------------------------------------------
if (DB_USER === '' || DB_PASS === '') {
    fail('Kredensial database belum diisi. Isi DB_USER dan DB_PASS di config.php '
        . '(database + user MySQL dibuat di cPanel, lalu import database.sql).', 500);
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    fail('Tidak dapat terhubung ke database MySQL. Periksa DB_HOST, DB_NAME, '
        . 'DB_USER, dan DB_PASS di config.php (pastikan database sudah dibuat '
        . 'dan database.sql sudah di-import).', 500);
}

// ---------------------------------------------------------------------------
// Input
// ---------------------------------------------------------------------------
$action = $_GET['action'] ?? '';
$body   = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw  = file_get_contents('php://input');
    $body = $raw !== '' ? json_decode($raw, true) : [];
    if (!is_array($body)) {
        fail('Body request harus berupa JSON yang valid.');
    }
}

// ---------------------------------------------------------------------------
// Utilitas validasi & casting
// ---------------------------------------------------------------------------
$PERAN_VALID = ['Inventory', 'Produksi', 'Owner'];

/**
 * Verifikasi role + user_id terhadap tabel pengguna, lalu pastikan peran
 * tersebut termasuk yang diizinkan untuk aksi ini. Mengembalikan baris
 * pengguna yang sudah terverifikasi.
 */
function requireRole(PDO $pdo, array $body, array $allowed, array $peranValid): array
{
    $role   = $body['role'] ?? '';
    $userId = $body['user_id'] ?? null;

    if (!in_array($role, $peranValid, true)) {
        fail('Peran tidak valid. Peran yang dikenal: Inventory, Produksi, Owner.');
    }
    if (!in_array($role, $allowed, true)) {
        fail('Peran ' . $role . ' tidak berhak menjalankan aksi ini.');
    }
    if ($userId === null || !is_numeric($userId)) {
        fail('user_id wajib disertakan.');
    }

    $st = $pdo->prepare('SELECT id_pengguna, nama, peran FROM pengguna WHERE id_pengguna = ?');
    $st->execute([(int) $userId]);
    $user = $st->fetch();
    if (!$user) {
        fail('id_pengguna tidak ditemukan di tabel pengguna.');
    }
    if ($user['peran'] !== $role) {
        fail('id_pengguna ' . (int) $userId . ' bukan pengguna dengan peran ' . $role . '.');
    }
    return $user;
}

/** Ambil field wajib dari body; kosong -> fail. */
function reqField(array $body, string $key): mixed
{
    $v = $body[$key] ?? null;
    if ($v === null || $v === '') {
        fail('Field "' . $key . '" wajib diisi.');
    }
    return $v;
}

/** Validasi angka >= 0 (desimal di ERD memakai decimal(12,2)). */
function reqNumber(array $body, string $key, bool $allowZero = true): float
{
    $v = $body[$key] ?? null;
    if ($v === null || $v === '' || !is_numeric($v)) {
        fail('Field "' . $key . '" harus berupa angka.');
    }
    $f = (float) $v;
    if ($allowZero ? $f < 0 : $f <= 0) {
        fail('Field "' . $key . '" harus lebih dari 0.');
    }
    return $f;
}

// Casting baris agar angka DECIMAL/INT dari MySQL sampai ke JS sebagai angka,
// bukan string (JS membandingkan stok_saat_ini < stok_minimal secara numerik).
function castBahan(array $r): array
{
    $r['id_bahan_baku']  = (int) $r['id_bahan_baku'];
    $r['stok_saat_ini']  = (float) $r['stok_saat_ini'];
    $r['stok_minimal']   = (float) $r['stok_minimal'];
    return $r;
}
function castJadi(array $r): array
{
    $r['id_barang_jadi'] = (int) $r['id_barang_jadi'];
    $r['stok_saat_ini']  = (float) $r['stok_saat_ini'];
    return $r;
}
function castPengajuan(array $r): array
{
    $r['id_pengajuan'] = (int) $r['id_pengajuan'];
    $r['id_pengaju']   = (int) $r['id_pengaju'];
    $r['id_penyetuju'] = $r['id_penyetuju'] === null ? null : (int) $r['id_penyetuju'];
    foreach ($r['detail'] as &$d) {
        $d['id_detail_pengajuan'] = (int) $d['id_detail_pengajuan'];
        $d['id_pengajuan']        = (int) $d['id_pengajuan'];
        $d['id_bahan_baku']       = (int) $d['id_bahan_baku'];
        $d['jumlah']              = (float) $d['jumlah'];
    }
    unset($d);
    return $r;
}
function castPo(array $r): array
{
    $r['id_po']                = (int) $r['id_po'];
    $r['id_pengajuan']         = (int) $r['id_pengajuan'];
    $r['id_supplier']          = (int) $r['id_supplier'];
    $r['id_karyawan_inventory'] = (int) $r['id_karyawan_inventory'];
    foreach ($r['detail'] as &$d) {
        $d['id_detail_po']    = (int) $d['id_detail_po'];
        $d['id_po']            = (int) $d['id_po'];
        $d['id_bahan_baku']    = (int) $d['id_bahan_baku'];
        $d['jumlah_dipesan']   = (float) $d['jumlah_dipesan'];
        $d['jumlah_diterima']  = (float) $d['jumlah_diterima'];
        $d['harga_satuan']     = $d['harga_satuan'] === null ? null : (float) $d['harga_satuan'];
    }
    unset($d);
    return $r;
}
function castProduksi(array $r): array
{
    $r['id_pengeluaran']        = (int) $r['id_pengeluaran'];
    $r['id_barang_jadi']        = (int) $r['id_barang_jadi'];
    $r['jumlah_target_produksi'] = (float) $r['jumlah_target_produksi'];
    $r['id_karyawan_produksi']  = (int) $r['id_karyawan_produksi'];
    $r['id_karyawan_inventory'] = (int) $r['id_karyawan_inventory'];
    foreach ($r['detail'] as &$d) {
        $d['id_detail_pengeluaran'] = (int) $d['id_detail_pengeluaran'];
        $d['id_pengeluaran']        = (int) $d['id_pengeluaran'];
        $d['id_bahan_baku']         = (int) $d['id_bahan_baku'];
        $d['jumlah']                = (float) $d['jumlah'];
        $d['harga_satuan_bahan']    = $d['harga_satuan_bahan'] === null ? null : (float) $d['harga_satuan_bahan'];
    }
    unset($d);
    return $r;
}

// ---------------------------------------------------------------------------
// AKSI BACA — action=init (GET)
// Mengembalikan SEMUA data yang ditampilkan UI, dalam bentuk yang sama
// dengan `state` di prototipe (pengguna, bahan, jadi, supplier, pengajuan
// + detail, po + detail, produksi + detail).
// ---------------------------------------------------------------------------
if ($action === 'init') {
    try {
        $pengguna = $pdo->query('SELECT id_pengguna, nama, peran, telepon, created_at FROM pengguna ORDER BY id_pengguna')
            ->fetchAll();
        foreach ($pengguna as &$u) {
            $u['id_pengguna'] = (int) $u['id_pengguna'];
        }
        unset($u);

        $supplier = $pdo->query('SELECT id_supplier, nama_supplier, kontak, alamat, created_at FROM supplier ORDER BY id_supplier')
            ->fetchAll();
        foreach ($supplier as &$s) {
            $s['id_supplier'] = (int) $s['id_supplier'];
        }
        unset($s);

        $bahan = array_map('castBahan', $pdo->query(
            'SELECT id_bahan_baku, nama_bahan_baku, satuan, stok_saat_ini, stok_minimal, updated_at FROM bahan_baku ORDER BY id_bahan_baku'
        )->fetchAll());

        $jadi = array_map('castJadi', $pdo->query(
            'SELECT id_barang_jadi, nama_barang_jadi, satuan, stok_saat_ini, updated_at FROM barang_jadi ORDER BY id_barang_jadi'
        )->fetchAll());

        $pengajuanRows = $pdo->query(
            'SELECT id_pengajuan, id_pengaju, id_penyetuju, tanggal_pengajuan, status_pengajuan, created_at '
            . 'FROM pengajuan_pengadaan ORDER BY id_pengajuan'
        )->fetchAll();
        $detailPengajuan = $pdo->query(
            'SELECT id_detail_pengajuan, id_pengajuan, id_bahan_baku, jumlah FROM detail_pengajuan ORDER BY id_detail_pengajuan'
        )->fetchAll();
        $byPengajuan = [];
        foreach ($detailPengajuan as $d) {
            $byPengajuan[(int) $d['id_pengajuan']][] = $d;
        }
        $pengajuan = [];
        foreach ($pengajuanRows as $row) {
            $row['detail'] = $byPengajuan[(int) $row['id_pengajuan']] ?? [];
            $pengajuan[]   = castPengajuan($row);
        }

        $poRows = $pdo->query(
            'SELECT id_po, id_pengajuan, id_supplier, id_karyawan_inventory, no_nota_supplier, '
            . 'tanggal_terima, status_po, status_pembayaran, catatan_pemeriksaan, created_at '
            . 'FROM pemesanan_pembelian ORDER BY id_po'
        )->fetchAll();
        $detailPo = $pdo->query(
            'SELECT id_detail_po, id_po, id_bahan_baku, jumlah_dipesan, jumlah_diterima, harga_satuan, kondisi '
            . 'FROM detail_pemesanan_pembelian ORDER BY id_detail_po'
        )->fetchAll();
        $byPo = [];
        foreach ($detailPo as $d) {
            $byPo[(int) $d['id_po']][] = $d;
        }
        $po = [];
        foreach ($poRows as $row) {
            $row['detail'] = $byPo[(int) $row['id_po']] ?? [];
            $po[]          = castPo($row);
        }

        $prodRows = $pdo->query(
            'SELECT id_pengeluaran, no_batch_produksi, id_barang_jadi, jumlah_target_produksi, '
            . 'id_karyawan_produksi, id_karyawan_inventory, tanggal_keluar, created_at '
            . 'FROM pengeluaran_bahan_baku ORDER BY id_pengeluaran'
        )->fetchAll();
        $detailProd = $pdo->query(
            'SELECT id_detail_pengeluaran, id_pengeluaran, id_bahan_baku, jumlah, harga_satuan_bahan '
            . 'FROM detail_pengeluaran_bahan_baku ORDER BY id_detail_pengeluaran'
        )->fetchAll();
        $byProd = [];
        foreach ($detailProd as $d) {
            $byProd[(int) $d['id_pengeluaran']][] = $d;
        }
        $produksi = [];
        foreach ($prodRows as $row) {
            $row['detail'] = $byProd[(int) $row['id_pengeluaran']] ?? [];
            $produksi[]    = castProduksi($row);
        }

        ok(['data' => [
            'pengguna'  => $pengguna,
            'supplier'  => $supplier,
            'bahan'     => $bahan,
            'jadi'      => $jadi,
            'pengajuan' => $pengajuan,
            'po'        => $po,
            'produksi'  => $produksi,
        ]]);
    } catch (PDOException $e) {
        fail('Gagal membaca data dari database: ' . $e->getMessage(), 500);
    }
}

// ---------------------------------------------------------------------------
// AKSI MUTASI (POST, body JSON, wajib role + user_id)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Aksi tidak dikenal: "' . $action . '".');
}

switch ($action) {

    // -- Buat pengajuan_pengadaan (hanya Inventory) -------------------------
    // Body: { role, user_id, id_bahan_baku, jumlah }
    // Sama seperti prototipe: status_pengajuan 'Menunggu', id_penyetuju NULL,
    // tanggal_pengajuan = tanggal hari ini, 1 baris detail_pengajuan.
    case 'create_pengajuan': {
        $user = requireRole($pdo, $body, ['Inventory'], $PERAN_VALID);
        $idBahan = (int) reqField($body, 'id_bahan_baku');
        $jumlah  = reqNumber($body, 'jumlah', false);

        $st = $pdo->prepare('SELECT id_bahan_baku FROM bahan_baku WHERE id_bahan_baku = ?');
        $st->execute([$idBahan]);
        if (!$st->fetch()) {
            fail('id_bahan_baku tidak ditemukan.');
        }

        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare(
                'INSERT INTO pengajuan_pengadaan (id_pengaju, id_penyetuju, tanggal_pengajuan, status_pengajuan, created_at) '
                . 'VALUES (?, NULL, CURDATE(), "Menunggu", NOW())'
            );
            $st->execute([(int) $user['id_pengguna']]);
            $idPengajuan = (int) $pdo->lastInsertId();

            $st = $pdo->prepare('INSERT INTO detail_pengajuan (id_pengajuan, id_bahan_baku, jumlah) VALUES (?, ?, ?)');
            $st->execute([$idPengajuan, $idBahan, $jumlah]);
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            fail('Gagal membuat pengajuan: ' . $e->getMessage(), 500);
        }
        ok(['id_pengajuan' => $idPengajuan]);
    }

    // -- Setujui / tolak pengajuan (hanya Owner) ----------------------------
    // Body: { role, user_id, id_pengajuan, keputusan: "Disetujui"|"Ditolak" }
    // Sama seperti prototipe: hanya bila status masih "Menunggu";
    // id_penyetuju diisi id_pengguna Owner yang menyetujui/menolak.
    // (Kolom `catatan` TIDAK ada di ERD pengajuan_pengadaan, jadi alasan
    //  penolakan tidak disimpan — sesuai aturan nama/kolom persis ERD.)
    case 'decide_pengajuan': {
        $user = requireRole($pdo, $body, ['Owner'], $PERAN_VALID);
        $id   = (int) reqField($body, 'id_pengajuan');
        $keputusan = reqField($body, 'keputusan');
        if (!in_array($keputusan, ['Disetujui', 'Ditolak'], true)) {
            fail('keputusan harus "Disetujui" atau "Ditolak".');
        }

        $st = $pdo->prepare('SELECT id_pengajuan, status_pengajuan FROM pengajuan_pengadaan WHERE id_pengajuan = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            fail('id_pengajuan tidak ditemukan.');
        }
        if ($row['status_pengajuan'] !== 'Menunggu') {
            fail('Pengajuan ini sudah ' . $row['status_pengajuan'] . ' — tidak bisa diubah lagi.');
        }

        $st = $pdo->prepare('UPDATE pengajuan_pengadaan SET status_pengajuan = ?, id_penyetuju = ? WHERE id_pengajuan = ?');
        $st->execute([$keputusan, (int) $user['id_pengguna'], $id]);
        ok(['id_pengajuan' => $id, 'status_pengajuan' => $keputusan]);
    }

    // -- Buat pemesanan_pembelian / PO (hanya Inventory) --------------------
    // Body: { role, user_id, id_pengajuan, id_supplier, no_nota_supplier,
    //         detail: [{ id_bahan_baku, jumlah_dipesan, harga_satuan }] }
    // Sama seperti prototipe: status_po 'Diproses', status_pembayaran
    // 'Belum Bayar', tanggal_terima NULL, jumlah_diterima 0. Kolom kondisi
    // mengikuti DEFAULT skema ('Sesuai') sampai penerimaan mencatat
    // kondisi sebenarnya — skema database.sql melarang kondisi NULL.
    // Pengajuan harus berstatus 'Disetujui' dan belum pernah dibuatkan PO.
    case 'create_po': {
        $user = requireRole($pdo, $body, ['Inventory'], $PERAN_VALID);
        $idPengajuan = (int) reqField($body, 'id_pengajuan');
        $idSupplier  = (int) reqField($body, 'id_supplier');
        $noNota      = trim((string) reqField($body, 'no_nota_supplier'));
        $details     = $body['detail'] ?? null;
        if (!is_array($details) || count($details) === 0) {
            fail('detail PO wajib diisi (minimal 1 bahan).');
        }

        $st = $pdo->prepare('SELECT id_pengajuan, status_pengajuan FROM pengajuan_pengadaan WHERE id_pengajuan = ?');
        $st->execute([$idPengajuan]);
        $pj = $st->fetch();
        if (!$pj) {
            fail('id_pengajuan tidak ditemukan.');
        }
        if ($pj['status_pengajuan'] !== 'Disetujui') {
            fail('PO hanya bisa dibuat dari pengajuan yang sudah Disetujui (saat ini: ' . $pj['status_pengajuan'] . ').');
        }
        $st = $pdo->prepare('SELECT id_po FROM pemesanan_pembelian WHERE id_pengajuan = ?');
        $st->execute([$idPengajuan]);
        if ($st->fetch()) {
            fail('Pengajuan ini sudah dibuatkan PO.');
        }
        $st = $pdo->prepare('SELECT id_supplier FROM supplier WHERE id_supplier = ?');
        $st->execute([$idSupplier]);
        if (!$st->fetch()) {
            fail('id_supplier tidak ditemukan.');
        }

        $clean = [];
        foreach ($details as $d) {
            if (!is_array($d) || !isset($d['id_bahan_baku'], $d['jumlah_dipesan'])) {
                fail('Setiap detail PO wajib berisi id_bahan_baku dan jumlah_dipesan.');
            }
            $idB = (int) $d['id_bahan_baku'];
            $jml = (float) $d['jumlah_dipesan'];
            // harga_satuan adalah hasil NEGOSIASI dengan supplier (bukan
            // harga paten): wajib diisi di form, tanpa daftar harga bawaan.
            // Kosong / tidak ada -> tolak, JANGAN diam-diam jadi 0.
            if (!isset($d['harga_satuan']) || $d['harga_satuan'] === '' || $d['harga_satuan'] === null || !is_numeric($d['harga_satuan'])) {
                fail('harga_satuan wajib diisi (harga hasil negosiasi dengan supplier).');
            }
            $hrg = (float) $d['harga_satuan'];
            if ($jml <= 0) {
                fail('jumlah_dipesan harus lebih dari 0.');
            }
            if ($hrg < 0) {
                fail('harga_satuan tidak boleh negatif.');
            }
            $st = $pdo->prepare('SELECT id_bahan_baku FROM bahan_baku WHERE id_bahan_baku = ?');
            $st->execute([$idB]);
            if (!$st->fetch()) {
                fail('id_bahan_baku ' . $idB . ' tidak ditemukan.');
            }
            $clean[] = [$idB, $jml, $hrg];
        }

        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare(
                'INSERT INTO pemesanan_pembelian (id_pengajuan, id_supplier, id_karyawan_inventory, no_nota_supplier, '
                . 'tanggal_terima, status_po, status_pembayaran, catatan_pemeriksaan, created_at) '
                . 'VALUES (?, ?, ?, ?, NULL, "Diproses", "Belum Bayar", "", NOW())'
            );
            $st->execute([$idPengajuan, $idSupplier, (int) $user['id_pengguna'], $noNota]);
            $idPo = (int) $pdo->lastInsertId();

            $st = $pdo->prepare(
                // Kolom kondisi TIDAK diisi di sini: di skema database.sql
                // kondisinya NOT NULL DEFAULT 'Sesuai'. Nilai kondisi yang
                // sebenarnya baru dicatat saat penerimaan (receive_po).
                'INSERT INTO detail_pemesanan_pembelian (id_po, id_bahan_baku, jumlah_dipesan, jumlah_diterima, harga_satuan) '
                . 'VALUES (?, ?, ?, 0, ?)'
            );
            foreach ($clean as [$idB, $jml, $hrg]) {
                $st->execute([$idPo, $idB, $jml, $hrg]);
            }
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            fail('Gagal membuat PO: ' . $e->getMessage(), 500);
        }
        ok(['id_po' => $idPo]);
    }

    // -- Konfirmasi penerimaan PO (hanya Inventory) -------------------------
    // Body: { role, user_id, id_po, catatan_pemeriksaan?,
    //         detail: [{ id_detail_po, jumlah_diterima, kondisi }] }
    // Sama seperti prototipe: stok_saat_ini bahan_baku += jumlah_diterima
    // (berapa pun kondisinya — Sesuai/Rusak/Kurang), status_po 'Diterima',
    // tanggal_terima = hari ini. Hanya bila status_po masih 'Diproses'.
    case 'receive_po': {
        requireRole($pdo, $body, ['Inventory'], $PERAN_VALID);
        $idPo = (int) reqField($body, 'id_po');
        $catatan = isset($body['catatan_pemeriksaan']) ? trim((string) $body['catatan_pemeriksaan']) : '';
        $details = $body['detail'] ?? null;
        if (!is_array($details) || count($details) === 0) {
            fail('detail penerimaan wajib diisi.');
        }

        $st = $pdo->prepare('SELECT id_po, status_po FROM pemesanan_pembelian WHERE id_po = ?');
        $st->execute([$idPo]);
        $po = $st->fetch();
        if (!$po) {
            fail('id_po tidak ditemukan.');
        }
        if ($po['status_po'] !== 'Diproses') {
            fail('PO ini sudah ' . $po['status_po'] . ' — tidak bisa diterima lagi.');
        }

        $clean = [];
        $kondisiValid = ['Sesuai', 'Rusak', 'Kurang'];
        foreach ($details as $d) {
            if (!is_array($d) || !isset($d['id_detail_po'], $d['jumlah_diterima'], $d['kondisi'])) {
                fail('Setiap detail penerimaan wajib berisi id_detail_po, jumlah_diterima, dan kondisi.');
            }
            if (!in_array($d['kondisi'], $kondisiValid, true)) {
                fail('kondisi harus Sesuai, Rusak, atau Kurang.');
            }
            $jml = (float) $d['jumlah_diterima'];
            if ($jml < 0) {
                fail('jumlah_diterima tidak boleh negatif.');
            }
            $st = $pdo->prepare(
                'SELECT id_detail_po, id_bahan_baku FROM detail_pemesanan_pembelian WHERE id_detail_po = ? AND id_po = ?'
            );
            $st->execute([(int) $d['id_detail_po'], $idPo]);
            $row = $st->fetch();
            if (!$row) {
                fail('id_detail_po ' . (int) $d['id_detail_po'] . ' tidak ditemukan pada PO ini.');
            }
            $clean[] = [(int) $row['id_detail_po'], (int) $row['id_bahan_baku'], $jml, $d['kondisi']];
        }

        try {
            $pdo->beginTransaction();
            $stDetail = $pdo->prepare(
                'UPDATE detail_pemesanan_pembelian SET jumlah_diterima = ?, kondisi = ? WHERE id_detail_po = ?'
            );
            $stStok = $pdo->prepare(
                'UPDATE bahan_baku SET stok_saat_ini = stok_saat_ini + ?, updated_at = NOW() WHERE id_bahan_baku = ?'
            );
            foreach ($clean as [$idDetail, $idBahan, $jml, $kondisi]) {
                $stDetail->execute([$jml, $kondisi, $idDetail]);
                $stStok->execute([$jml, $idBahan]);
            }
            if ($catatan !== '') {
                $st = $pdo->prepare(
                    'UPDATE pemesanan_pembelian SET status_po = "Diterima", tanggal_terima = NOW(), catatan_pemeriksaan = ? WHERE id_po = ?'
                );
                $st->execute([$catatan, $idPo]);
            } else {
                $st = $pdo->prepare(
                    'UPDATE pemesanan_pembelian SET status_po = "Diterima", tanggal_terima = NOW() WHERE id_po = ?'
                );
                $st->execute([$idPo]);
            }
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            fail('Gagal mengonfirmasi penerimaan PO: ' . $e->getMessage(), 500);
        }
        ok(['id_po' => $idPo, 'status_po' => 'Diterima']);
    }

    // -- Buat pengeluaran_bahan_baku batch (Inventory / Produksi) -----------
    // Body: { role, user_id, no_batch_produksi, id_barang_jadi,
    //         jumlah_target_produksi, id_bahan_baku, jumlah,
    //         harga_satuan_bahan }
    // Sama seperti prototipe (form hanya 1 bahan per batch): cek stok cukup
    // dulu, lalu stok_saat_ini bahan_baku -= jumlah dan stok_saat_ini
    // barang_jadi += jumlah_target_produksi. id_karyawan_produksi diisi
    // user yang login bila perannya Produksi (kalau Inventory yang input,
    // dipakai id_pengguna Produksi pertama); id_karyawan_inventory diisi
    // user yang login bila perannya Inventory (kalau Produksi yang input,
    // dipakai id_pengguna Inventory pertama).
    case 'create_pengeluaran': {
        $user = requireRole($pdo, $body, ['Inventory', 'Produksi'], $PERAN_VALID);
        $noBatch = trim((string) reqField($body, 'no_batch_produksi'));
        $idJadi  = (int) reqField($body, 'id_barang_jadi');
        $target  = reqNumber($body, 'jumlah_target_produksi', false);
        $idBahan = (int) reqField($body, 'id_bahan_baku');
        $jumlah  = reqNumber($body, 'jumlah', false);
        $harga   = isset($body['harga_satuan_bahan']) && $body['harga_satuan_bahan'] !== ''
            ? (float) $body['harga_satuan_bahan'] : 0;
        if ($harga < 0) {
            fail('harga_satuan_bahan tidak boleh negatif.');
        }

        $st = $pdo->prepare('SELECT id_barang_jadi, nama_barang_jadi FROM barang_jadi WHERE id_barang_jadi = ?');
        $st->execute([$idJadi]);
        if (!$st->fetch()) {
            fail('id_barang_jadi tidak ditemukan.');
        }

        // Tentukan id_karyawan_produksi & id_karyawan_inventory seperti prototipe.
        $idProd = $user['peran'] === 'Produksi' ? (int) $user['id_pengguna'] : null;
        $idInv  = $user['peran'] === 'Inventory' ? (int) $user['id_pengguna'] : null;
        if ($idProd === null || $idInv === null) {
            $st = $pdo->query("SELECT id_pengguna, peran FROM pengguna WHERE peran IN ('Produksi','Inventory') ORDER BY id_pengguna");
            foreach ($st->fetchAll() as $u) {
                if ($idProd === null && $u['peran'] === 'Produksi') {
                    $idProd = (int) $u['id_pengguna'];
                }
                if ($idInv === null && $u['peran'] === 'Inventory') {
                    $idInv = (int) $u['id_pengguna'];
                }
            }
        }
        if ($idProd === null || $idInv === null) {
            fail('Tabel pengguna harus berisi minimal satu pengguna Inventory dan satu pengguna Produksi.');
        }

        try {
            $pdo->beginTransaction();
            // Kunci baris bahan agar dua pengeluaran bersamaan tidak
            // sama-sama lolos dari cek stok.
            $st = $pdo->prepare('SELECT stok_saat_ini, nama_bahan_baku FROM bahan_baku WHERE id_bahan_baku = ? FOR UPDATE');
            $st->execute([$idBahan]);
            $bhn = $st->fetch();
            if (!$bhn) {
                $pdo->rollBack();
                fail('id_bahan_baku tidak ditemukan.');
            }
            if ((float) $bhn['stok_saat_ini'] < $jumlah) {
                $pdo->rollBack();
                fail('Stok ' . $bhn['nama_bahan_baku'] . ' tidak cukup (' . $bhn['stok_saat_ini']
                    . ' tersedia, butuh ' . $jumlah . ').');
            }

            $st = $pdo->prepare(
                'INSERT INTO pengeluaran_bahan_baku (no_batch_produksi, id_barang_jadi, jumlah_target_produksi, '
                . 'id_karyawan_produksi, id_karyawan_inventory, tanggal_keluar, created_at) '
                . 'VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
            );
            $st->execute([$noBatch, $idJadi, $target, $idProd, $idInv]);
            $idKeluar = (int) $pdo->lastInsertId();

            $st = $pdo->prepare(
                'INSERT INTO detail_pengeluaran_bahan_baku (id_pengeluaran, id_bahan_baku, jumlah, harga_satuan_bahan) '
                . 'VALUES (?, ?, ?, ?)'
            );
            $st->execute([$idKeluar, $idBahan, $jumlah, $harga]);

            $st = $pdo->prepare('UPDATE bahan_baku SET stok_saat_ini = stok_saat_ini - ?, updated_at = NOW() WHERE id_bahan_baku = ?');
            $st->execute([$jumlah, $idBahan]);

            $st = $pdo->prepare('UPDATE barang_jadi SET stok_saat_ini = stok_saat_ini + ?, updated_at = NOW() WHERE id_barang_jadi = ?');
            $st->execute([$target, $idJadi]);

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            fail('Gagal membuat pengeluaran batch: ' . $e->getMessage(), 500);
        }
        ok(['id_pengeluaran' => $idKeluar]);
    }

    // -- Tambah bahan_baku (hanya Inventory) --------------------------------
    // Body: { role, user_id, nama_bahan_baku, satuan, stok_saat_ini, stok_minimal }
    case 'create_bahan': {
        requireRole($pdo, $body, ['Inventory'], $PERAN_VALID);
        $nama  = trim((string) reqField($body, 'nama_bahan_baku'));
        $sat   = trim((string) reqField($body, 'satuan'));
        $stok  = reqNumber($body, 'stok_saat_ini');
        $min   = reqNumber($body, 'stok_minimal');

        $st = $pdo->prepare(
            'INSERT INTO bahan_baku (nama_bahan_baku, satuan, stok_saat_ini, stok_minimal, updated_at) '
            . 'VALUES (?, ?, ?, ?, NOW())'
        );
        $st->execute([$nama, $sat, $stok, $min]);
        ok(['id_bahan_baku' => (int) $pdo->lastInsertId()]);
    }

    // -- Tambah barang_jadi (hanya Inventory) -------------------------------
    // Body: { role, user_id, nama_barang_jadi, satuan, stok_saat_ini }
    // (barang_jadi di ERD TIDAK punya stok_minimal — sama seperti prototipe.)
    case 'create_jadi': {
        requireRole($pdo, $body, ['Inventory'], $PERAN_VALID);
        $nama = trim((string) reqField($body, 'nama_barang_jadi'));
        $sat  = trim((string) reqField($body, 'satuan'));
        $stok = reqNumber($body, 'stok_saat_ini');

        $st = $pdo->prepare(
            'INSERT INTO barang_jadi (nama_barang_jadi, satuan, stok_saat_ini, updated_at) VALUES (?, ?, ?, NOW())'
        );
        $st->execute([$nama, $sat, $stok]);
        ok(['id_barang_jadi' => (int) $pdo->lastInsertId()]);
    }

    // -- Tambah supplier (hanya Inventory) ----------------------------------
    // Body: { role, user_id, nama_supplier, kontak?, alamat? }
    case 'create_supplier': {
        requireRole($pdo, $body, ['Inventory'], $PERAN_VALID);
        $nama   = trim((string) reqField($body, 'nama_supplier'));
        $kontak = isset($body['kontak']) ? trim((string) $body['kontak']) : '';
        $alamat = isset($body['alamat']) ? trim((string) $body['alamat']) : '';

        $st = $pdo->prepare(
            'INSERT INTO supplier (nama_supplier, kontak, alamat, created_at) VALUES (?, ?, ?, NOW())'
        );
        $st->execute([$nama, $kontak, $alamat]);
        ok(['id_supplier' => (int) $pdo->lastInsertId()]);
    }

    default:
        fail('Aksi tidak dikenal: "' . $action . '".');
}
