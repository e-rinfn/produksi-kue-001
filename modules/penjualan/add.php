<?php
require_once '../../config/config.php';
require_once '../../includes/functions.php';
checkAuth();

$page_title = 'Tambah Penjualan';
$active_page = 'penjualan';

// Ambil data kue: total stok per jenis (termasuk expired) + batch tertua (expired atau tidak)
$sql = "
SELECT 
    j.id_jenis_kue,
    j.nama_kue,
    j.harga_jual AS harga_tertua,
    total_stok.total_stock,
    -- stok tertua: id, jumlah, exp (termasuk expired)
    (SELECT sk1.id_stok_kue
     FROM stok_kue sk1
     WHERE sk1.id_jenis_kue = j.id_jenis_kue
         AND sk1.jumlah > 0
     ORDER BY sk1.tanggal_kadaluarsa ASC, sk1.id_stok_kue ASC
     LIMIT 1
    ) AS id_stok_kue_tertua,
    (SELECT sk2.jumlah
     FROM stok_kue sk2
     WHERE sk2.id_jenis_kue = j.id_jenis_kue
         AND sk2.jumlah > 0
     ORDER BY sk2.tanggal_kadaluarsa ASC, sk2.id_stok_kue ASC
     LIMIT 1
    ) AS jumlah_tertua,
    (SELECT sk3.tanggal_kadaluarsa
     FROM stok_kue sk3
     WHERE sk3.id_jenis_kue = j.id_jenis_kue
         AND sk3.jumlah > 0
     ORDER BY sk3.tanggal_kadaluarsa ASC, sk3.id_stok_kue ASC
     LIMIT 1
    ) AS exp_tertua
FROM jenis_kue j

-- total semua stok per jenis (termasuk expired)
JOIN (
        SELECT id_jenis_kue, SUM(jumlah) AS total_stock
        FROM stok_kue
        WHERE jumlah > 0
        GROUP BY id_jenis_kue
) AS total_stok ON total_stok.id_jenis_kue = j.id_jenis_kue

ORDER BY j.nama_kue
";
$stmt = $db->prepare($sql);
$stmt->execute();
$kue_tersedia = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Ambil data pelanggan
$stmt = $db->query("SELECT * FROM pelanggan ORDER BY nama_pelanggan");
$pelanggan = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id_pelanggan = $_POST['id_pelanggan'] ?: NULL;
    $tanggal_penjualan = $_POST['tanggal_penjualan'];
    $metode_pembayaran = $_POST['metode_pembayaran'];
    $kue = $_POST['kue'];
    $jumlah = $_POST['jumlah'];
    $harga = $_POST['harga'];
    $diskon = $_POST['diskon'] ?? [];
    $poin_input = $_POST['poin'] ?? []; // Pastikan ini array, jika tidak, gunakan array kosong
    $poin_dipakai = $_POST['poin_dipakai'] ?? 0;
    $catatan = $_POST['catatan'];

    try {
        $db->beginTransaction();

        // Hitung total harga dan total poin
        $total_harga = 0;
        $total_poin = 0;

        // Menghitung total harga dan total poin
        foreach ($kue as $i => $id_stok_kue) {
            $subtotal = ($harga[$i] - ($diskon[$i] ?? 0)) * $jumlah[$i];
            $total_harga += $subtotal;

            // Gunakan poin yang dimasukkan oleh pengguna untuk setiap item
            $poin_per_item = isset($poin_input[$i]) ? $poin_input[$i] : 0; // Pastikan poin per item ada
            $total_poin += $poin_per_item * $jumlah[$i]; // Menambahkan poin sesuai jumlah item
        }

        // Hitung nilai poin yang dipakai (1 poin = Rp 1)
        $nilai_poin_dipakai = min($poin_dipakai, $total_harga);
        $total_bayar = $total_harga - $nilai_poin_dipakai;

        // 1. Insert penjualan
        $stmt = $db->prepare("INSERT INTO penjualan 
                            (tanggal_penjualan, id_pelanggan, id_admin, total_harga, total_diskon, 
                             total_poin_dipakai, nilai_poin_dipakai, total_bayar, metode_pembayaran, catatan) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $tanggal_penjualan,
            $id_pelanggan,
            $_SESSION['user_id'],
            $total_harga,
            array_sum($diskon), // Gunakan array_sum untuk diskon
            $poin_dipakai,
            $nilai_poin_dipakai,
            $total_bayar,
            $metode_pembayaran,
            $catatan
        ]);
        $id_penjualan = $db->lastInsertId();

        // 2. Insert detail penjualan dan update stok
        foreach ($kue as $i => $id_stok_kue) {
            $subtotal = ($harga[$i] - ($diskon[$i] ?? 0)) * $jumlah[$i];
            $poin_diberikan = isset($poin_input[$i]) ? $poin_input[$i] * $jumlah[$i] : 0;

            // Dapatkan id_jenis_kue dari stok_kue
            $stmt = $db->prepare("SELECT id_jenis_kue FROM stok_kue WHERE id_stok_kue = ?");
            $stmt->execute([$id_stok_kue]);
            $stok_info = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $db->prepare("INSERT INTO detail_penjualan 
                                (id_penjualan, id_jenis_kue, id_stok_kue, jumlah, harga_satuan, 
                                 diskon_satuan, subtotal, poin_diberikan) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $id_penjualan,
                $stok_info['id_jenis_kue'],
                $id_stok_kue,
                $jumlah[$i],
                $harga[$i],
                $diskon[$i] ?? 0,
                $subtotal,
                $poin_diberikan
            ]);
        }

        // --- Mulai FIFO stock deduction ---
        foreach ($_POST['kue'] as $i => $id_stok_tertua) {
            $qty = (int) $_POST['jumlah'][$i];

            // 1) Cari jenis kue dari stok_tertua
            $stmt = $db->prepare(
                "SELECT id_jenis_kue 
            FROM stok_kue 
            WHERE id_stok_kue = ?"
            );
            $stmt->execute([$id_stok_tertua]);
            $id_jenis_kue = $stmt->fetchColumn();

            // 2) Ambil semua batch stok (FIFO) utk jenis kue ini
            $stmt = $db->prepare(
                "SELECT id_stok_kue, jumlah
            FROM stok_kue
            WHERE id_jenis_kue = :jk
                AND jumlah > 0
            ORDER BY tanggal_kadaluarsa ASC, id_stok_kue ASC"
            );
            $stmt->execute([':jk' => $id_jenis_kue]);
            $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 3) Kurangi stok di tiap batch sesuai FIFO
            $sisa = $qty;
            foreach ($batches as $batch) {
                if ($sisa <= 0) break;

                $stokId   = $batch['id_stok_kue'];
                $tersedia = (int) $batch['jumlah'];

                if ($tersedia >= $sisa) {
                    // batch cukup untuk menutupi sisa pemesanan
                    $upd = $db->prepare(
                        "UPDATE stok_kue 
                    SET jumlah = jumlah - :q 
                    WHERE id_stok_kue = :id"
                    );
                    $upd->execute([':q' => $sisa, ':id' => $stokId]);
                    $sisa = 0;
                } else {
                    // habiskan batch ini, lanjut ke batch berikut
                    $upd = $db->prepare(
                        "UPDATE stok_kue 
                    SET jumlah = 0 
                    WHERE id_stok_kue = :id"
                    );
                    $upd->execute([':id' => $stokId]);
                    $sisa -= $tersedia;
                }
            }

            // Opsional: tangani jika $sisa > 0 (stok tidak cukup)
            if ($sisa > 0) {
                throw new Exception("Stok untuk jenis kue #{$id_jenis_kue} tidak mencukupi.");
            }
        }
        // --- Selesai FIFO stock deduction ---

        // 3. Update poin pelanggan jika ada
        if ($id_pelanggan) {
            // Tambah poin baru (dari penjualan ini)
            $stmt = $db->prepare("UPDATE pelanggan SET total_poin = total_poin + ? WHERE id_pelanggan = ?");
            $stmt->execute([$total_poin, $id_pelanggan]);

            // Kurangi poin yang dipakai (dari poin pelanggan)
            if ($poin_dipakai > 0) {
                $stmt = $db->prepare("UPDATE pelanggan SET total_poin = total_poin - ? WHERE id_pelanggan = ?");
                $stmt->execute([$poin_dipakai, $id_pelanggan]);
            }
        }

        $db->commit();
        redirectWithMessage("index.php", 'success', 'Penjualan berhasil dicatat');
    } catch (Exception $e) {
        $db->rollBack();
        redirectWithMessage('add.php', 'danger', 'Error: ' . $e->getMessage());
    }
}

include '../../includes/header.php';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <style>
        .stock-info {
            font-size: 0.8rem;
            color: #666;
            margin-top: 3px;
        }

        .max-stock {
            color: #dc3545;
            font-weight: bold;
        }

        .item-row {
            border: 1px solid #dee2e6;
            border-radius: 5px;
            margin-bottom: 10px;
            padding: 15px;
            background-color: #f8f9fa;
        }

        .btn-remove-item {
            margin-top: 28px;
        }
    </style>
</head>

<body data-pc-preset="preset-1" data-pc-direction="ltr" data-pc-theme="light">
    <!-- [ Pre-loader ] start -->
    <div class="loader-bg">
        <div class="loader-track">
            <div class="loader-fill"></div>
        </div>
    </div>
    <!-- [ Pre-loader ] End -->

    <?php include '../../includes/sidebar.php'; ?>
    <?php include '../../includes/navbar.php'; ?>

    <!-- [ Main Content ] start -->
    <div class="pc-container">
        <div class="pc-content">
            <!-- [ breadcrumb ] start -->
            <div class="page-header">
                <div class="page-block">
                    <div class="row align-items-center">
                        <div class="col-md-12">
                            <div class="page-header-title">
                                <h5 class="m-b-10">Transaksi</h5>
                            </div>
                            <ul class="breadcrumb">
                                <li class="breadcrumb-item"><a href="index.php">Penjualan</a></li>
                                <li class="breadcrumb-item active">Tambah</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            <!-- [ breadcrumb ] end -->
            <div class="row">
                <!-- [ Main Content ] start -->
                <div class="card">
                    <div class="card-header">
                        <h3>Tambah Penjualan</h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="formPenjualan">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Tanggal Penjualan</label>
                                        <input type="datetime-local" name="tanggal_penjualan" class="form-control" value="<?= date('Y-m-d\TH:i') ?>" required>
                                    </div>

                                    <div class="form-group">
                                        <label>Pelanggan</label>
                                        <select name="id_pelanggan" class="form-control select2" id="selectPelanggan">
                                            <option value="">-- Umum --</option>
                                            <?php foreach ($pelanggan as $p): ?>
                                                <option value="<?= $p['id_pelanggan'] ?>"
                                                    data-kategori="<?= $p['id_kategori_pelanggan'] ?>"
                                                    data-poin="<?= $p['total_poin'] ?>">
                                                    <?= $p['nama_pelanggan'] ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Metode Pembayaran</label>
                                        <select name="metode_pembayaran" class="form-control" required>
                                            <option value="tunai">Tunai</option>
                                            <option value="transfer">Transfer</option>
                                            <option value="kredit">Kredit</option>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label>Catatan</label>
                                        <textarea name="catatan" class="form-control"></textarea>
                                    </div>
                                </div>
                            </div>

                            <!-- [ List Item Penjualan ] Start -->
                            <div id="itemContainer">
                                <!-- Item akan ditambahkan disini menggunakan JavaScript -->
                            </div>
                            <!-- [ List Item Penjualan ] End -->

                            <div class="form-group mt-4">
                                <button type="button" id="btnAddItem" class="btn btn-primary"><i class="fas fa-plus"></i> Tambah Item</button>
                            </div>

                            <div class="form-group">
                                <label>Total Harga</label>
                                <input type="text" id="totalHarga" class="form-control" readonly>
                            </div>

                            <button type="button" id="btnSimpan" class="btn btn-primary">Simpan</button>
                            <a href="index.php" class="btn btn-secondary">Batal</a>
                        </form>
                    </div>
                </div>
            </div>
            <!-- [ Main Content ] end -->
        </div>
    </div>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        $(document).ready(function() {
            // Function to format currency
            function formatCurrency(amount) {
                return 'Rp ' + amount.toLocaleString('id-ID');
            }

            // Function to calculate total
            function hitungTotal() {
                let totalHarga = 0;

                $('.item-row').each(function() {
                    let harga = parseFloat($(this).find('.harga').val()) || 0;
                    let jumlah = parseInt($(this).find('.jumlah').val()) || 0;

                    totalHarga += harga * jumlah;
                });

                $('#totalHarga').val(formatCurrency(totalHarga));
            }

            // Function to initialize item events
            function initItemEvents(item) {
                let selectKue = item.find('.select-kue');
                let jumlahInput = item.find('.jumlah');
                let hargaInput = item.find('.harga');
                let stockInfo = item.find('.stock-amount');
                let maxStockInfo = item.find('.max-stock-info');

                // Update stock info and price when cake is selected
                selectKue.on('change', function() {
                    let selectedOption = $(this).find('option:selected');
                    let stock = parseInt(selectedOption.data('stock')) || 0;
                    let price = parseFloat(selectedOption.data('harga')) || 0;
                    let expDate = selectedOption.data('exp') || '';

                    // Update display
                    stockInfo.text(stock);
                    hargaInput.val(price);

                    // Set max value for quantity input
                    jumlahInput.attr('max', stock);

                    // Check stock availability
                    if (stock <= 0) {
                        maxStockInfo.html('<span class="max-stock">Stok habis!</span>');
                        jumlahInput.val(0).prop('disabled', true);
                    } else {
                        maxStockInfo.html('');
                        jumlahInput.val(1).prop('disabled', false);

                        // Set max quantity validation
                        if (parseInt(jumlahInput.val()) > stock) {
                            jumlahInput.val(stock);
                            maxStockInfo.html('<span class="max-stock">Jumlah melebihi stok tersedia!</span>');
                        }
                    }

                    hitungTotal();
                });

                // Validate quantity input
                jumlahInput.on('input', function() {
                    let maxStock = parseInt($(this).attr('max')) || 0;
                    let enteredQty = parseInt($(this).val()) || 0;

                    if (enteredQty > maxStock) {
                        maxStockInfo.html('<span class="max-stock">Jumlah melebihi stok tersedia!</span>');
                        $(this).val(maxStock);
                    } else if (enteredQty <= 0) {
                        $(this).val(1);
                    } else {
                        maxStockInfo.html('');
                    }

                    hitungTotal();
                });

                // Recalculate when price changes
                hargaInput.on('input', function() {
                    hitungTotal();
                });
            }

            // Add new item
            $('#btnAddItem').on('click', function() {
                let itemIndex = $('.item-row').length;

                let item = `
                <div class="item-row row mb-3 p-2 border">
                    <div class="col-md-4">
                        <label>Kue</label>
                        <select name="kue[]" class="form-control select-kue" required>
                            <option value="">-- Pilih Kue --</option>
                            <?php foreach ($kue_tersedia as $k): ?>
                                <option 
                                    value="<?= $k['id_stok_kue_tertua'] ?>"
                                    data-harga="<?= $k['harga_tertua'] ?>"
                                    data-stock="<?= $k['total_stock'] ?>"
                                    data-exp="<?= $k['exp_tertua'] ?>">
                                    <?= htmlspecialchars($k['nama_kue']) ?> (Stok: <?= $k['total_stock'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="stock-info">Stok tersedia: <span class="stock-amount">0</span></div>
                    </div>
                    <div class="col-md-2">
                        <label>Jumlah</label>
                        <input type="number" name="jumlah[]" class="form-control jumlah" value="1" min="1" required>
                        <div class="max-stock-info"></div>
                    </div>
                    <div class="col-md-2">
                        <label>Harga</label>
                        <input type="number" name="harga[]" class="form-control harga" required>
                    </div>
                    <div class="col-md-2">
                        <label>Nabung per Item</label>
                        <input type="number" name="poin[]" class="form-control poin" value="0" min="0">
                    </div>
                    <div class="col-md-2 d-flex align-items-center justify-content-center">
                        <button type="button" class="btn btn-danger btn-remove-item"><i class="fas fa-times"></i></button>
                    </div>
                </div>`;

                $('#itemContainer').append(item);
                initItemEvents($('#itemContainer .item-row').last());

                // Hitung total setelah menambahkan item
                hitungTotal();
            });

            // Remove item (using event delegation)
            $(document).on('click', '.btn-remove-item', function() {
                if ($('.item-row').length > 1) {
                    $(this).closest('.item-row').remove();
                    hitungTotal();
                } else {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Peringatan',
                        text: 'Minimal harus ada satu item penjualan'
                    });
                }
            });

            // Save button confirmation
            $('#btnSimpan').on('click', function(e) {
                e.preventDefault();

                // Validate form
                let isValid = true;
                $('.item-row').each(function() {
                    let kue = $(this).find('.select-kue').val();
                    let jumlah = $(this).find('.jumlah').val();

                    if (!kue || !jumlah || parseInt(jumlah) <= 0) {
                        isValid = false;
                        return false;
                    }
                });

                if (!isValid) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Harap isi semua item dengan benar!'
                    });
                    return;
                }

                if ($('.item-row').length === 0) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Minimal harus ada satu item penjualan!'
                    });
                    return;
                }

                Swal.fire({
                    title: 'Konfirmasi',
                    text: 'Apakah Anda yakin ingin menyimpan penjualan ini?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#3085d6',
                    cancelButtonColor: '#d33',
                    confirmButtonText: 'Ya, Simpan!',
                    cancelButtonText: 'Batal'
                }).then((result) => {
                    if (result.isConfirmed) {
                        $('#formPenjualan').submit();
                    }
                });
            });

            // Initialize first item automatically
            if ($('.item-row').length === 0) {
                $('#btnAddItem').trigger('click');
            }
        });
    </script>
</body>

</html>