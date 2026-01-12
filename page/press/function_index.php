<?php
// Database Connection
require '../../conn.php';

// Session Check
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Press') {
    header('location: ../../index.php');
    exit;
}

// Auto Refresh 60s
echo '<meta http-equiv="refresh" content="60">';

// Logout
if (isset($_POST['btn_logout'])) {
    session_destroy();
    header('location: ../../index.php');
    exit;
}

date_default_timezone_set('Asia/Jakarta');
// Function Production Date
function getProductionDateOnly($datetime)
{
    $time = date('H:i', strtotime($datetime));
    $date = date('Y-m-d', strtotime($datetime));

    if ($time < '09:00') {
        return date('Y-m-d', strtotime($date . ' -1 day'));
    }
    return $date;
}

// Function Shift
function getShift($time)
{
    if ($time >= '09:00' && $time < '18:00') return 1;
    if ($time >= '18:00' || $time < '01:30') return 2;
    return 3;
}

// SINGLE SOURCE OF TRUTH (WAJIB)
$now = date('Y-m-d H:i:s');

// KHUSUS TESTING
// $now = '2026-01-07 01:40:00';

$currentDate  = getProductionDateOnly($now);               // PRODUCTION DATE
$currentShift = getShift(date('H:i', strtotime($now)));   // PRODUCTION SHIFT

$currentDateTr  = $currentDate . ' ' . date('H:i:s', strtotime($now));
$currentShiftTr = $currentShift;

$productionDateDisplay  = date('d/m/Y', strtotime($currentDate));
$productionShiftDisplay = $currentShift;

// Function Update Table History_LS
function updateHistoryLS($conn)
{
    $partResult = mysqli_query($conn, "
        SELECT part_code, qty_press, qty_paint 
        FROM part
    ");

    while ($row = mysqli_fetch_assoc($partResult)) {

        $partCode = $row['part_code'];
        $qtyPress = (int)$row['qty_press'];
        $qtyPaint = (int)$row['qty_paint'];

        // CEK HISTORY_LS TABLE FOR MONTHLY IF EXISTS UPDATE ELSE INSERT
        $historyCheck = mysqli_query($conn, "
            SELECT * 
            FROM history_ls
            WHERE part_code = '$partCode'
            AND DATE_FORMAT(date_prod, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
        ");

        // TABLE HISTORY_LS UPDATE / INSERT
        if (mysqli_num_rows($historyCheck) > 0) {

            // UPDATE
            mysqli_query($conn, "
                UPDATE history_ls
                SET qty_end_press = $qtyPress,
                    qty_end_paint = $qtyPaint
                WHERE part_code = '$partCode'
                AND DATE_FORMAT(date_prod, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
            ");
        } else {

            // INSERT
            mysqli_query($conn, "
                INSERT INTO history_ls
                (date_prod, part_code, qty_end_press, qty_end_paint)
                VALUES
                (CURDATE(), '$partCode', $qtyPress, $qtyPaint)
            ");
        }

        // END STOCK ASSY FROM TRANSACTION TABLE
        $assyStockQuery = "
            SELECT SUM(qty) AS total_assy
            FROM `transaction`
            WHERE status = 'ASSY'
            AND DATE_FORMAT(date_tr, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
            AND part_code = '$partCode'
        ";

        $assyStockResult = mysqli_query($conn, $assyStockQuery);
        $assyStockData   = mysqli_fetch_assoc($assyStockResult);
        $totalAssy       = (int)$assyStockData['total_assy'];

        // UPDATE QTY_END_ASSY IN HISTORY_LS
        mysqli_query($conn, "
            UPDATE history_ls
            SET qty_end_assy = $totalAssy
            WHERE part_code = '$partCode'
            AND DATE_FORMAT(date_prod, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
        ");
    }
}
updateHistoryLS($conn);

// INIT PART DATA
$komponen = [];
$partResult = mysqli_query($conn, "
    SELECT part_code, part_name 
    FROM part
");

while ($row = mysqli_fetch_assoc($partResult)) {
    $komponen[$row['part_code']] = [
        'part_code' => $row['part_code'],
        'part_name' => $row['part_name'],

        'total_press' => 0,
        'total_paint' => 0,
        'total_assy'  => 0,

        'daily_press' => 0,
        'daily_paint' => 0,
        'daily_assy'  => 0,

        'shift1_press' => 0,
        'shift1_paint' => 0,
        'shift1_assy'  => 0,

        'shift2_press' => 0,
        'shift2_paint' => 0,
        'shift2_assy'  => 0,

        'shift3_press' => 0,
        'shift3_paint' => 0,
        'shift3_assy'  => 0,

        'stock_press' => 0,
        'stock_paint' => 0,

        'qty_bk_press' => 0,
        'qty_bk_paint' => 0,
        'qty_bk_assy'  => 0
    ];
}

// ---------- TRANSACTIONS (MONTHLY ONLY) ----------
function getTrans($status)
{
    return "
        SELECT part_code, date_tr, shift, qty
        FROM `transaction`
        WHERE status = '$status'
          AND DATE_FORMAT(date_tr, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
    ";
}

$pressData = mysqli_query($conn, getTrans('PRESS'));
$paintData = mysqli_query($conn, getTrans('PAINT'));
$assyData  = mysqli_query($conn, getTrans('ASSY'));

// ---------- TRANSACTION LOOP (PRESS / PAINT / ASSY) ----------
foreach (
    [
        'PRESS' => ['total_press', 'daily_press', 'press'],
        'PAINT' => ['total_paint', 'daily_paint', 'paint'],
        'ASSY'  => ['total_assy',  'daily_assy',  'assy']
    ] as $status => $map
) {

    $result = ${strtolower($status) . 'Data'};

    while ($tr = mysqli_fetch_assoc($result)) {

        if (!isset($komponen[$tr['part_code']])) continue;

        $p     = $tr['part_code'];
        $qty   = (int)$tr['qty'];
        $shift = (int)$tr['shift'];

        // ⚠️ PAKAI TANGGAL ASLI TRANSAKSI
        $trDate = date('Y-m-d', strtotime($tr['date_tr']));

        // TOTAL BULANAN
        $komponen[$p][$map[0]] += $qty;

        // DAILY + SHIFT (BERDASARKAN currentDate)
        if ($trDate === $currentDate) {
            $komponen[$p][$map[1]] += $qty;
            $komponen[$p]["shift{$shift}_{$map[2]}"] += $qty;
        }
    }
}

// HITUNG LIVE STOCK (AMBIL DARI TABLE PART)
foreach ($komponen as &$d) {

    $q = mysqli_query($conn, "
        SELECT qty_press, qty_paint 
        FROM part 
        WHERE part_code = '{$d['part_code']}'
    ");

    if ($s = mysqli_fetch_assoc($q)) {
        $d['stock_press'] = (int)$s['qty_press'];
        $d['stock_paint'] = (int)$s['qty_paint'];
    }
}
unset($d);


// HANDLE FINISH PRODUCTION - PRESS
if (isset($_POST['btn_finish'])) {

    $partCode = $_POST['part_code'] ?? '';
    $qty = (int)($_POST['qty'] ?? 0);

    if ($partCode === '' || $qty <= 0) {
        echo "<script>alert('Input tidak valid');history.back();</script>";
        exit;
    }

    mysqli_begin_transaction($conn);

    try {
        mysqli_query($conn, "
            INSERT INTO `transaction`
            (part_code,date_tr,shift,qty,status)
            VALUES ('$partCode', '$currentDateTr', '$currentShiftTr', $qty, 'PRESS')
        ");

        mysqli_query($conn, "
            UPDATE part SET qty_press = qty_press + $qty
            WHERE part_code='$partCode'
        ");

        mysqli_commit($conn);
        echo "<script>alert('Finish production recorded');location.href='index.php';</script>";
        exit;
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo "<script>alert('ERROR');history.back();</script>";
        exit;
    }
}

// HANDLE BLUE & YELLOW VOUCHER
if (isset($_POST['btn_voucher'])) {

    $partCode = $_POST['part_code'] ?? '';
    $area     = $_POST['area'] ?? '';
    $qty      = isset($_POST['qty']) ? (int)$_POST['qty'] : 0;

    // VALIDASI INPUT
    if ($partCode === '' || $qty <= 0) {
        echo "<script>
            alert('Input tidak valid');
            history.back();
        </script>";
        exit;
    }

    // START TRANSACTION
    mysqli_begin_transaction($conn);

    try {
        // CEK HISTORY_LS TABLE FOR MONTHLY IF EXISTS UPDATE ELSE INSERT
        $historyCheck = mysqli_query($conn, "
            SELECT * FROM history_ls
            WHERE part_code = '$partCode'
            AND DATE_FORMAT(date_prod, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
        ");

        // TABLE HISTORY_LS UPDATE / INSERT
        if (mysqli_num_rows($historyCheck) > 0) {
            if (!mysqli_query($conn, "
                UPDATE history_ls
                SET qty_bk_{$area} = qty_bk_{$area} + $qty
                WHERE part_code = '$partCode'
                AND DATE_FORMAT(date_prod, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
            ")) {
                throw new Exception('Gagal update history_ls');
            }
        } else {
            if (!mysqli_query($conn, "
                INSERT INTO history_ls
                (date_prod, part_code, qty_bk_{$area})
                VALUES
                (CURDATE(), '$partCode', $qty)
            ")) {
                throw new Exception('Gagal insert history_ls');
            }
        }

        // UPDATE TABLE PART STOCK
        if ($area === 'assy') {
            if (!mysqli_query($conn, "
                UPDATE part
                SET qty_paint = qty_paint - $qty
                WHERE part_code = '$partCode'
            ")) {
                throw new Exception('Gagal update part stock untuk assy');
            }
        } else {
            if (!mysqli_query($conn, "
                UPDATE part
                SET qty_{$area} = qty_{$area} - $qty
                WHERE part_code = '$partCode'
            ")) {
                throw new Exception('Gagal update part stock untuk press/paint');
            }
        }

        // COMMIT
        mysqli_commit($conn);

        echo "<script>
            alert('Voucher recorded successfully');
            location.href='index.php';
        </script>";
        exit;
    } catch (Exception $e) {
        mysqli_rollback($conn);

        echo "<script>
            alert('ERROR: {$e->getMessage()}');
            history.back();
        </script>";
        exit;
    }
}
?>
<!-- @raffizh24 -->