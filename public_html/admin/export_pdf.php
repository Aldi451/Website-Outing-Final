<?php
/**
 * Export PDF Resmi Laporan Outing
 * Menggunakan FPDF 1.86 (100% Native PHP / Kompatibel Shared Hosting InfinityFree)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../libs/fpdf/fpdf.php';

require_admin();

$pdo = getDB();
$outingId = (int)($_GET['id'] ?? 0);

if ($outingId <= 0) {
    die('ID Outing tidak valid.');
}

// 1. Ambil Data Outing
$stmt = $pdo->prepare('SELECT * FROM outings WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $outingId]);
$outing = $stmt->fetch();

if (!$outing) {
    die('Data outing tidak ditemukan.');
}

// 2. Query Peserta
$partStmt = $pdo->prepare('
    SELECT p.*, u.user_id, u.nama 
    FROM outing_participants p
    JOIN users u ON p.user_id = u.id
    WHERE p.outing_id = :oid
    ORDER BY u.nama ASC
');
$partStmt->execute([':oid' => $outingId]);
$participants = $partStmt->fetchAll();

// 3. Query Rundown
$rundownStmt = $pdo->prepare('
    SELECT * FROM rundown 
    WHERE outing_id = :oid 
    ORDER BY day_number ASC, sort_order ASC, start_time ASC
');
$rundownStmt->execute([':oid' => $outingId]);
$rundowns = $rundownStmt->fetchAll();

// 4. Query Purchases & Rekap Kategori
$purchStmt = $pdo->prepare('
    SELECT pu.*, c.name AS category_name
    FROM purchases pu
    JOIN categories c ON pu.category_id = c.id
    WHERE pu.outing_id = :oid
    ORDER BY pu.purchase_date ASC, pu.id ASC
');
$purchStmt->execute([':oid' => $outingId]);
$purchases = $purchStmt->fetchAll();

$recapStmt = $pdo->prepare('
    SELECT c.name, COUNT(pu.id) AS total_items, COALESCE(SUM(pu.total_price), 0) AS total_amount
    FROM categories c
    JOIN purchases pu ON c.id = pu.category_id AND pu.outing_id = :oid
    GROUP BY c.id, c.name
    ORDER BY total_amount DESC
');
$recapStmt->execute([':oid' => $outingId]);
$categoryRecap = $recapStmt->fetchAll();

// Hitung Ringkasan
$totalSpent = 0;
foreach ($purchases as $p) {
    $totalSpent += $p['total_price'];
}
$sisaBudget = $outing['budget'] - $totalSpent;

$countHadir = 0;
$countBatal = 0;
$countSudahBayar = 0;
foreach ($participants as $pt) {
    if ($pt['attendance_status'] === 'ikut') $countHadir++;
    if ($pt['attendance_status'] === 'batal') $countBatal++;
    if ($pt['payment_status'] === 'sudah') $countSudahBayar++;
}

// Class Custom PDF
class OutingReportPDF extends FPDF {
    public $outingName = '';
    public $dest = '';

    function Header() {
        $this->SetFont('Helvetica', 'B', 15);
        $this->SetTextColor(37, 99, 235);
        $this->Cell(0, 7, 'LAPORAN RESMI KEGIATAN OUTING', 0, 1, 'C');
        
        $this->SetFont('Helvetica', 'B', 12);
        $this->SetTextColor(30, 41, 59);
        $this->Cell(0, 6, strtoupper($this->outingName), 0, 1, 'C');

        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(0, 5, 'Lokasi: ' . $this->dest . ' | Dicetak: ' . date('d F Y, H:i') . ' WIB', 0, 1, 'C');

        $this->SetDrawColor(203, 213, 225);
        $this->SetLineWidth(0.5);
        $this->Line(10, $this->GetY() + 2, 200, $this->GetY() + 2);
        $this->Ln(6);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Helvetica', 'I', 8);
        $this->SetTextColor(148, 163, 184);
        $this->Cell(0, 10, 'Outing Management System - Halaman ' . $this->PageNo() . ' dari {nb}', 0, 0, 'C');
    }

    function SectionTitle($title) {
        $this->SetFont('Helvetica', 'B', 11);
        $this->SetFillColor(241, 245, 249);
        $this->SetTextColor(15, 23, 42);
        $this->Cell(0, 7, '  ' . $title, 0, 1, 'L', true);
        $this->Ln(2);
    }
}

// Generate PDF
$pdf = new OutingReportPDF('P', 'mm', 'A4');
$pdf->outingName = $outing['outing_name'];
$pdf->dest = $outing['destination'];
$pdf->AliasNbPages();
$pdf->SetMargins(10, 15, 10);
$pdf->AddPage();

// -------------------------------------------------------------
// 1. INFORMASI UMUM & RINGKASAN KEGIATAN
// -------------------------------------------------------------
$pdf->SectionTitle('1. INFORMASI & RINGKASAN KEGIATAN');

$pdf->SetFont('Helvetica', '', 9);
$pdf->SetTextColor(30, 41, 59);

$pdf->Cell(45, 6, 'Nama Kegiatan', 0, 0);
$pdf->Cell(5, 6, ':', 0, 0);
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell(140, 6, $outing['outing_name'], 0, 1);

$pdf->SetFont('Helvetica', '', 9);
$pdf->Cell(45, 6, 'Destinasi / Lokasi', 0, 0);
$pdf->Cell(5, 6, ':', 0, 0);
$pdf->Cell(140, 6, $outing['destination'], 0, 1);

$pdf->Cell(45, 6, 'Waktu Pelaksanaan', 0, 0);
$pdf->Cell(5, 6, ':', 0, 0);
$pdf->Cell(140, 6, format_date_id($outing['start_date']) . ' s/d ' . format_date_id($outing['end_date']), 0, 1);

$pdf->Cell(45, 6, 'Status Kegiatan', 0, 0);
$pdf->Cell(5, 6, ':', 0, 0);
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell(140, 6, strtoupper($outing['status']), 0, 1);

$pdf->Ln(2);

// Tabel Ringkasan Keuangan & Peserta
$pdf->SetFont('Helvetica', 'B', 8.5);
$pdf->SetFillColor(226, 232, 240);
$pdf->Cell(47, 6, 'Total Alokasi Budget', 1, 0, 'C', true);
$pdf->Cell(47, 6, 'Realisasi Pengeluaran', 1, 0, 'C', true);
$pdf->Cell(48, 6, 'Sisa Saldo Anggaran', 1, 0, 'C', true);
$pdf->Cell(48, 6, 'Peserta Hadir / Total', 1, 1, 'C', true);

$pdf->SetFont('Helvetica', '', 9);
$pdf->Cell(47, 7, format_rupiah($outing['budget']), 1, 0, 'C');
$pdf->Cell(47, 7, format_rupiah($totalSpent), 1, 0, 'C');
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell(48, 7, format_rupiah($sisaBudget), 1, 0, 'C');
$pdf->SetFont('Helvetica', '', 9);
$pdf->Cell(48, 7, $countHadir . ' Hadir / ' . count($participants) . ' Terdaftar', 1, 1, 'C');

$pdf->Ln(5);

// -------------------------------------------------------------
// 2. REKAPITULASI PENGELUARAN PER KATEGORI
// -------------------------------------------------------------
$pdf->SectionTitle('2. REKAPITULASI PENGELUARAN PER KATEGORI');

$pdf->SetFont('Helvetica', 'B', 8.5);
$pdf->SetFillColor(241, 245, 249);
$pdf->Cell(10, 6, 'No', 1, 0, 'C', true);
$pdf->Cell(70, 6, 'Nama Kategori', 1, 0, 'L', true);
$pdf->Cell(35, 6, 'Jumlah Transaksi', 1, 0, 'C', true);
$pdf->Cell(45, 6, 'Total Nominal (Rp)', 1, 0, 'R', true);
$pdf->Cell(30, 6, 'Persentase', 1, 1, 'C', true);

$pdf->SetFont('Helvetica', '', 8.5);
$noCat = 1;
if (empty($categoryRecap)) {
    $pdf->Cell(190, 6, 'Belum ada catatan pengeluaran.', 1, 1, 'C');
} else {
    foreach ($categoryRecap as $cr) {
        $pct = $totalSpent > 0 ? round(($cr['total_amount'] / $totalSpent) * 100, 1) : 0;
        $pdf->Cell(10, 6, $noCat++, 1, 0, 'C');
        $pdf->Cell(70, 6, $cr['name'], 1, 0, 'L');
        $pdf->Cell(35, 6, $cr['total_items'] . ' item', 1, 0, 'C');
        $pdf->Cell(45, 6, format_rupiah($cr['total_amount']), 1, 0, 'R');
        $pdf->Cell(30, 6, $pct . ' %', 1, 1, 'C');
    }
    $pdf->SetFont('Helvetica', 'B', 8.5);
    $pdf->Cell(115, 6, 'TOTAL REALISASI BIAYA', 1, 0, 'R');
    $pdf->Cell(45, 6, format_rupiah($totalSpent), 1, 0, 'R');
    $pdf->Cell(30, 6, '100 %', 1, 1, 'C');
}

$pdf->Ln(5);

// -------------------------------------------------------------
// 3. RINCIAN TRANSAKSI PENGELUARAN (DETAIL PEMBELIAN)
// -------------------------------------------------------------
$pdf->SectionTitle('3. DETAIL TRANSAKSI PENGELUARAN & PEMBELIAN');

$pdf->SetFont('Helvetica', 'B', 8);
$pdf->SetFillColor(241, 245, 249);
$pdf->Cell(8, 6, 'No', 1, 0, 'C', true);
$pdf->Cell(20, 6, 'Tanggal', 1, 0, 'C', true);
$pdf->Cell(35, 6, 'Kategori', 1, 0, 'L', true);
$pdf->Cell(55, 6, 'Nama Barang / Jasa', 1, 0, 'L', true);
$pdf->Cell(12, 6, 'Qty', 1, 0, 'C', true);
$pdf->Cell(28, 6, 'Harga Satuan', 1, 0, 'R', true);
$pdf->Cell(32, 6, 'Total Harga', 1, 1, 'R', true);

$pdf->SetFont('Helvetica', '', 8);
$noP = 1;
if (empty($purchases)) {
    $pdf->Cell(190, 6, 'Tidak ada data transaksi.', 1, 1, 'C');
} else {
    foreach ($purchases as $pu) {
        $pdf->Cell(8, 6, $noP++, 1, 0, 'C');
        $pdf->Cell(20, 6, date('d/m/Y', strtotime($pu['purchase_date'])), 1, 0, 'C');
        $pdf->Cell(35, 6, substr($pu['category_name'], 0, 20), 1, 0, 'L');
        $pdf->Cell(55, 6, substr($pu['item_name'], 0, 32), 1, 0, 'L');
        $pdf->Cell(12, 6, $pu['qty'], 1, 0, 'C');
        $pdf->Cell(28, 6, format_rupiah($pu['unit_price']), 1, 0, 'R');
        $pdf->Cell(32, 6, format_rupiah($pu['total_price']), 1, 1, 'R');
    }
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->Cell(158, 6, 'TOTAL PENGELUARAN', 1, 0, 'R');
    $pdf->Cell(32, 6, format_rupiah($totalSpent), 1, 1, 'R');
}

$pdf->Ln(5);

// -------------------------------------------------------------
// 4. SUSUNAN RUNDOWN ACARA
// -------------------------------------------------------------
$pdf->SectionTitle('4. SUSUNAN JADWAL RUNDOWN ACARA');

$pdf->SetFont('Helvetica', 'B', 8);
$pdf->SetFillColor(241, 245, 249);
$pdf->Cell(15, 6, 'Hari', 1, 0, 'C', true);
$pdf->Cell(25, 6, 'Waktu', 1, 0, 'C', true);
$pdf->Cell(65, 6, 'Aktivitas', 1, 0, 'L', true);
$pdf->Cell(40, 6, 'Lokasi', 1, 0, 'L', true);
$pdf->Cell(45, 6, 'Keterangan', 1, 1, 'L', true);

$pdf->SetFont('Helvetica', '', 8);
if (empty($rundowns)) {
    $pdf->Cell(190, 6, 'Belum ada jadwal rundown.', 1, 1, 'C');
} else {
    foreach ($rundowns as $rd) {
        $waktu = date('H:i', strtotime($rd['start_time'])) . (!empty($rd['end_time']) ? '-' . date('H:i', strtotime($rd['end_time'])) : '');
        $pdf->Cell(15, 6, 'Hari ' . $rd['day_number'], 1, 0, 'C');
        $pdf->Cell(25, 6, $waktu, 1, 0, 'C');
        $pdf->Cell(65, 6, substr($rd['activity'], 0, 38), 1, 0, 'L');
        $pdf->Cell(40, 6, substr($rd['location'] ?? '-', 0, 24), 1, 0, 'L');
        $pdf->Cell(45, 6, substr($rd['description'] ?? '-', 0, 26), 1, 1, 'L');
    }
}

$pdf->Ln(5);

// -------------------------------------------------------------
// 5. DAFTAR PESERTA & STATUS
// -------------------------------------------------------------
$pdf->SectionTitle('5. DAFTAR PESERTA & STATUS KEHADIRAN');

$pdf->SetFont('Helvetica', 'B', 8);
$pdf->SetFillColor(241, 245, 249);
$pdf->Cell(10, 6, 'No', 1, 0, 'C', true);
$pdf->Cell(30, 6, 'User ID', 1, 0, 'L', true);
$pdf->Cell(60, 6, 'Nama Lengkap', 1, 0, 'L', true);
$pdf->Cell(30, 6, 'Kehadiran', 1, 0, 'C', true);
$pdf->Cell(30, 6, 'Pembayaran', 1, 0, 'C', true);
$pdf->Cell(30, 6, 'Catatan', 1, 1, 'L', true);

$pdf->SetFont('Helvetica', '', 8);
$noPart = 1;
if (empty($participants)) {
    $pdf->Cell(190, 6, 'Belum ada peserta terdaftar.', 1, 1, 'C');
} else {
    foreach ($participants as $pt) {
        $pdf->Cell(10, 6, $noPart++, 1, 0, 'C');
        $pdf->Cell(30, 6, $pt['user_id'], 1, 0, 'L');
        $pdf->Cell(60, 6, substr($pt['nama'], 0, 32), 1, 0, 'L');
        $pdf->Cell(30, 6, strtoupper($pt['attendance_status']), 1, 0, 'C');
        $pdf->Cell(30, 6, strtoupper($pt['payment_status']), 1, 0, 'C');
        $pdf->Cell(30, 6, substr($pt['notes'] ?? '-', 0, 18), 1, 1, 'L');
    }
}

// Output PDF inline browser
$filename = 'Laporan_Outing_' . $outingId . '_' . date('Ymd') . '.pdf';
$pdf->Output('I', $filename);
exit;
