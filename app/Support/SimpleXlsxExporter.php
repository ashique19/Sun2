<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * Minimal XLSX writer (Open XML) without third-party packages.
 * Suitable for admin tabular exports that Excel / LibreOffice can open.
 */
class SimpleXlsxExporter
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<string|int|float|null>>  $rows
     */
    public function build(array $headers, array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($path === false) {
            throw new RuntimeException('Unable to create temporary export file.');
        }

        // tempnam creates an empty file; ZipArchive needs a non-existing path on some setups.
        @unlink($path);
        $path .= '.xlsx';

        $this->writeToFile($path, $headers, $rows);

        if (! is_file($path) || filesize($path) === 0) {
            @unlink($path);
            throw new RuntimeException('Generated XLSX file is missing or empty.');
        }

        $binary = file_get_contents($path);
        @unlink($path);

        if ($binary === false || $binary === '' || ! str_starts_with($binary, 'PK')) {
            throw new RuntimeException('Unable to read generated XLSX file.');
        }

        return $binary;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string|int|float|null>>  $rows
     */
    public function writeToFile(string $path, array $headers, array $rows): void
    {
        $sheetRowsXml = $this->sheetDataXml($headers, $rows);

        $files = [
            '[Content_Types].xml' => <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>
XML,
            '_rels/.rels' => <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML,
            'xl/workbook.xml' => <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Customers" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>
XML,
            'xl/_rels/workbook.xml.rels' => <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>
XML,
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                ."\n".'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                ."\n".'<sheetData>'.$sheetRowsXml.'</sheetData>'
                ."\n".'</worksheet>',
        ];

        $zip = new ZipArchive;
        $opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new RuntimeException('Unable to open XLSX archive for writing.');
        }

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string|int|float|null>>  $rows
     */
    private function sheetDataXml(array $headers, array $rows): string
    {
        $xml = $this->rowXml(1, $headers);
        $rowNumber = 2;

        foreach ($rows as $row) {
            $xml .= $this->rowXml($rowNumber, $row);
            $rowNumber++;
        }

        return $xml;
    }

    /**
     * @param  list<string|int|float|null>  $cells
     */
    private function rowXml(int $rowNumber, array $cells): string
    {
        $xml = '<row r="'.$rowNumber.'">';
        $col = 0;

        foreach ($cells as $value) {
            $ref = $this->columnLetter($col).$rowNumber;
            $escaped = htmlspecialchars((string) ($value ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $xml .= '<c r="'.$ref.'" t="inlineStr"><is><t>'.$escaped.'</t></is></c>';
            $col++;
        }

        return $xml.'</row>';
    }

    private function columnLetter(int $index): string
    {
        $letter = '';
        $n = $index;

        do {
            $letter = chr(65 + ($n % 26)).$letter;
            $n = intdiv($n, 26) - 1;
        } while ($n >= 0);

        return $letter;
    }
}
