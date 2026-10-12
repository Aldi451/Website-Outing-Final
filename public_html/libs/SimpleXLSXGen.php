<?php
/**
 * SimpleXLSXGen - Pure PHP XLSX Generator (No Composer, Zero Dependency)
 * Kompatibel 100% dengan Shared Hosting PHP 7.4 - 8.2+
 */

class SimpleXLSXGen {

    protected $sheets = [];
    protected $defaultSheetName = 'DATA';

    public function __construct() {
    }

    public static function fromArray(array $rows, $sheetName = 'DATA') {
        $xlsx = new self();
        $xlsx->addSheet($rows, $sheetName);
        return $xlsx;
    }

    public static function fromSheets(array $sheets) {
        $xlsx = new self();
        foreach ($sheets as $name => $rows) {
            $xlsx->addSheet($rows, $name);
        }
        return $xlsx;
    }

    public function addSheet(array $rows, $sheetName = null) {
        if (!$sheetName) {
            $sheetName = 'Sheet' . (count($this->sheets) + 1);
        }
        // Bersihkan nama sheet dari karakter terlarang Excel
        $cleanName = substr(preg_replace('/[\\\\\/*?:\[\]]/', '', $sheetName), 0, 31);
        if (empty($cleanName)) $cleanName = 'Sheet' . (count($this->sheets) + 1);

        $this->sheets[$cleanName] = $rows;
        return $this;
    }

    public function downloadAs($filename) {
        $content = $this->build();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        header('Content-Transfer-Encoding: binary');
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: max-age=0, must-revalidate');
        header('Pragma: public');
        echo $content;
        exit;
    }

    public function saveAs($filename) {
        $content = $this->build();
        return file_put_contents($filename, $content) !== false;
    }

    public function build() {
        if (empty($this->sheets)) {
            $this->addSheet([], $this->defaultSheetName);
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_');
        $zip = new ZipArchive();
        if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception('Gagal membuat file ZIP XLSX sementara');
        }

        // 1. [Content_Types].xml
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $contentTypes .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
        $contentTypes .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        $contentTypes .= '<Default Extension="xml" ContentType="application/xml"/>';
        $contentTypes .= '<Override PartName="/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        
        $sheetIndex = 1;
        foreach ($this->sheets as $name => $rows) {
            $contentTypes .= '<Override PartName="/worksheets/sheet' . $sheetIndex . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $sheetIndex++;
        }
        $contentTypes .= '</Types>';
        $zip->addFromString('[Content_Types].xml', $contentTypes);

        // 2. _rels/.rels
        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $rootRels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $rootRels .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="workbook.xml"/>';
        $rootRels .= '</Relationships>';
        $zip->addFromString('_rels/.rels', $rootRels);

        // 3. _rels/workbook.xml.rels
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $wbRels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $sheetIndex = 1;
        foreach ($this->sheets as $name => $rows) {
            $wbRels .= '<Relationship Id="rId' . $sheetIndex . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sheetIndex . '.xml"/>';
            $sheetIndex++;
        }
        $wbRels .= '</Relationships>';
        $zip->addFromString('_rels/workbook.xml.rels', $wbRels);

        // 4. workbook.xml
        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $wb .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $wb .= '<sheets>';
        $sheetIndex = 1;
        foreach ($this->sheets as $name => $rows) {
            $wb .= '<sheet name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" sheetId="' . $sheetIndex . '" r:id="rId' . $sheetIndex . '"/>';
            $sheetIndex++;
        }
        $wb .= '</sheets>';
        $wb .= '</workbook>';
        $zip->addFromString('workbook.xml', $wb);

        // 5. worksheets/sheet{N}.xml
        $sheetIndex = 1;
        foreach ($this->sheets as $name => $rows) {
            $ws = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $ws .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
            $ws .= '<sheetData>';
            
            $rowIndex = 1;
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $ws .= '<row r="' . $rowIndex . '">';
                $colIndex = 0;
                foreach ($row as $cellVal) {
                    $cellRef = $this->numToCol($colIndex) . $rowIndex;
                    if (is_numeric($cellVal) && !preg_match('/^0[0-9]+/', (string)$cellVal)) {
                        $ws .= '<c r="' . $cellRef . '"><v>' . $cellVal . '</v></c>';
                    } else {
                        $safeVal = htmlspecialchars((string)$cellVal, ENT_QUOTES, 'UTF-8');
                        $ws .= '<c r="' . $cellRef . '" t="inlineStr"><is><t>' . $safeVal . '</t></is></c>';
                    }
                    $colIndex++;
                }
                $ws .= '</row>';
                $rowIndex++;
            }
            $ws .= '</sheetData>';
            $ws .= '</worksheet>';
            $zip->addFromString('worksheets/sheet' . $sheetIndex . '.xml', $ws);
            $sheetIndex++;
        }

        $zip->close();
        $content = file_get_contents($tempFile);
        @unlink($tempFile);

        return $content;
    }

    protected function numToCol($n) {
        $col = '';
        $n = (int)$n;
        while ($n >= 0) {
            $col = chr(($n % 26) + 65) . $col;
            $n = intdiv($n, 26) - 1;
        }
        return $col;
    }
}
