<?php
/**
 * SimpleXLSX - Pure PHP XLSX Parser (No Composer, Zero Dependency)
 * Kompatibel 100% dengan Shared Hosting PHP 7.4 - 8.2+
 */

class SimpleXLSX {

    protected $rows = [];
    protected $error = null;

    public function __construct() {
    }

    public static function parse($filename) {
        $xlsx = new self();
        if ($xlsx->load($filename)) {
            return $xlsx;
        }
        return false;
    }

    public static function parseError() {
        return 'Gagal membaca atau memproses file Excel.';
    }

    public function rows($sheetIndex = 0) {
        return $this->rows;
    }

    public function getError() {
        return $this->error;
    }

    public function load($filename) {
        if (!file_exists($filename)) {
            $this->error = 'File tidak ditemukan: ' . $filename;
            return false;
        }

        // Cek jika file sebenarnya adalah CSV
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === 'csv') {
            return $this->loadCsv($filename);
        }

        $zip = new ZipArchive();
        if ($zip->open($filename) !== true) {
            // Coba buka sebagai CSV jika bukan format zip valid
            return $this->loadCsv($filename);
        }

        // 1. Baca sharedStrings.xml jika ada
        $sharedStrings = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml) {
            $xml = @simplexml_load_string($ssXml);
            if ($xml && isset($xml->si)) {
                foreach ($xml->si as $si) {
                    if (isset($si->t)) {
                        $sharedStrings[] = (string)$si->t;
                    } elseif (isset($si->r)) {
                        $t = '';
                        foreach ($si->r as $r) {
                            $t .= (string)$r->t;
                        }
                        $sharedStrings[] = $t;
                    } else {
                        $sharedStrings[] = '';
                    }
                }
            }
        }

        // 2. Baca sheet pertama: xl/worksheets/sheet1.xml
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!$sheetXml) {
            // Cari worksheet pertama yang tersedia
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (preg_match('/xl\/worksheets\/sheet\d+\.xml/i', $stat['name'])) {
                    $sheetXml = $zip->getFromIndex($i);
                    break;
                }
            }
        }

        if (!$sheetXml) {
            $zip->close();
            $this->error = 'Worksheet tidak ditemukan di dalam file Excel.';
            return false;
        }

        $xml = @simplexml_load_string($sheetXml);
        $zip->close();

        if (!$xml || !isset($xml->sheetData)) {
            $this->error = 'Gagal mem-parsing data worksheet XML.';
            return false;
        }

        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $rIndex = (int)$row['r'] - 1;
            $rowData = [];
            $colIndex = 0;

            foreach ($row->c as $c) {
                $r = (string)$c['r'];
                // Hitung indeks kolom dari koordinat cell (misal: "A1", "C2")
                preg_match('/^([A-Z]+)(\d+)$/', $r, $m);
                $expectedCol = $colIndex;
                if (!empty($m[1])) {
                    $expectedCol = $this->colToNum($m[1]);
                }

                // Isi cell kosong jika kolom melompat
                while ($colIndex < $expectedCol) {
                    $rowData[$colIndex] = '';
                    $colIndex++;
                }

                $type = (string)$c['t'];
                $val = '';

                if ($type === 's') { // shared string
                    $vIdx = (int)$c->v;
                    $val = $sharedStrings[$vIdx] ?? '';
                } elseif ($type === 'inlineStr') {
                    $val = (string)$c->is->t;
                } else {
                    $val = (string)$c->v;
                }

                $rowData[$colIndex] = trim($val);
                $colIndex++;
            }
            $rows[] = $rowData;
        }

        $this->rows = $rows;
        return true;
    }

    protected function loadCsv($filename) {
        $rows = [];
        if (($handle = fopen($filename, "r")) !== false) {
            while (($data = fgetcsv($handle, 10000, ",")) !== false) {
                // Bersihkan BOM jika ada
                if (empty($rows) && isset($data[0])) {
                    $data[0] = preg_replace('/^\xEF\xBB\xBF/', '', $data[0]);
                }
                $rows[] = $data;
            }
            fclose($handle);
            $this->rows = $rows;
            return true;
        }
        $this->error = 'Gagal membaca file CSV';
        return false;
    }

    protected function colToNum($col) {
        $num = 0;
        $len = strlen($col);
        for ($i = 0; $i < $len; $i++) {
            $num = $num * 26 + (ord($col[$i]) - 64);
        }
        return $num - 1;
    }
}
