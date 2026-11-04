<?php
// Minimal Excel (.xlsx) writer used by the report export (admin/GenerateReport.php?format=excel).
// An .xlsx file is a zip of small XML files; this builds one without any library or PHP extension
// (the zip is written "stored", i.e. uncompressed, which every Excel version opens).

function xlsxEsc($text)
{
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$text);
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

// Column letters: 0 => A, 25 => Z, 26 => AA ...
function xlsxCol($index)
{
    $s = '';
    $index++;
    while ($index > 0) {
        $m = ($index - 1) % 26;
        $s = chr(65 + $m) . $s;
        $index = intdiv($index - 1, 26);
    }
    return $s;
}

// Zip with no compression. $files is ['path/in/zip' => 'contents'].
function xlsxZip(array $files)
{
    $data = '';
    $central = '';
    $count = 0;
    foreach ($files as $name => $content) {
        $crc = (int)sprintf('%u', crc32($content));
        $size = strlen($content);
        $nameLen = strlen($name);
        $offset = strlen($data);
        $data .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 33, $crc, $size, $size, $nameLen, 0) . $name . $content;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 33, $crc, $size, $size, $nameLen, 0, 0, 0, 0, 0, $offset) . $name;
        $count++;
    }
    $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($data), 0);
    return $data . $central . $end;
}

// $meta: list of text lines shown at the top (site name, report title, scope, period, who/when).
// $blocks: ['section', text] | ['table', headers, widthsInMm, rows] | ['total', text] in the order they appear.
// Returns the bytes of the .xlsx file.
function xlsxBuild($sheetName, array $meta, array $blocks)
{
    // cell styles (index into cellXfs in styles.xml below)
    $S_DEFAULT = 0;
    $S_HEAD = 1;
    $S_BODY = 2;
    $S_TITLE = 3;
    $S_SECTION = 4;
    $S_TOTAL = 5;
    $S_META = 6;
    $S_MONEY = 7;

    $rowsXml = '';
    $r = 0;
    $colWidths = [];
    $cell = function ($col, $row, $value, $style) {
        $ref = xlsxCol($col) . $row;
        if ($value === null || $value === '') {
            return '<c r="' . $ref . '" s="' . $style . '"/>';
        }
        return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . xlsxEsc($value) . '</t></is></c>';
    };
    $addRow = function ($cellsXml, $height = null) use (&$rowsXml, &$r) {
        $r++;
        $rowsXml .= '<row r="' . $r . '"' . ($height ? ' ht="' . $height . '" customHeight="1"' : '') . '>' . $cellsXml($r) . '</row>';
    };

    foreach ($meta as $i => $line) {
        $style = $i === 1 ? $S_TITLE : ($i === 0 ? $S_TITLE : $S_META);
        $addRow(fn($row) => $cell(0, $row, $line, $style), $i <= 1 ? 20 : null);
    }
    $addRow(fn($row) => '');

    foreach ($blocks as $block) {
        if ($block[0] === 'section') {
            $addRow(fn($row) => $cell(0, $row, $block[1], $S_SECTION), 18);
        } elseif ($block[0] === 'total') {
            $text = $block[1];
            $addRow(fn($row) => $cell(0, $row, $text, $S_TOTAL));
        } elseif ($block[0] === 'table') {
            [, $headers, $widths, $rows] = $block;
            foreach ($widths as $i => $mm) {
                $colWidths[$i] = max($colWidths[$i] ?? 0, max(9, (int)round($mm * 0.55)));
            }
            $addRow(function ($row) use ($headers, $cell, $S_HEAD) {
                $x = '';
                foreach ($headers as $c => $h) {
                    $x .= $cell($c, $row, $h, $S_HEAD);
                }
                return $x;
            }, 22);
            if (empty($rows)) {
                $addRow(fn($row) => $cell(0, $row, 'No data for the selected filters.', $S_META));
            }
            foreach ($rows as $dataRow) {
                $addRow(function ($row) use ($dataRow, $cell, $S_BODY, $S_MONEY) {
                    $x = '';
                    foreach (array_values($dataRow) as $c => $v) {
                        // amounts like "2,000.00" become real numbers so they can be summed in Excel
                        if (is_string($v) && preg_match('/^-?\d{1,3}(,\d{3})*\.\d{2}$/', $v)) {
                            $x .= '<c r="' . xlsxCol($c) . $row . '" s="' . $S_MONEY . '"><v>' . str_replace(',', '', $v) . '</v></c>';
                        } else {
                            $x .= $cell($c, $row, $v, $S_BODY);
                        }
                    }
                    return $x;
                });
            }
            $addRow(fn($row) => ''); // blank line after each table
        }
    }

    $colsXml = '';
    if ($colWidths) {
        $colsXml = '<cols>';
        ksort($colWidths);
        foreach ($colWidths as $i => $w) {
            $colsXml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $colsXml .= '</cols>';
    }

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0" showGridLines="1"/></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>' . $colsXml
        . '<sheetData>' . $rowsXml . '</sheetData>'
        . '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
        . '<pageSetup orientation="landscape" fitToHeight="0"/>'
        . '</worksheet>';

    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
        . '<fonts count="5">'
        . '<font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FF1A3D1C"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="14"/><color rgb="FF2E7D32"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="12"/><color rgb="FF2E7D32"/><name val="Calibri"/></font>'
        . '<font><sz val="10"/><color rgb="FF5A5A5A"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFA9D8AB"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color rgb="FFB0B0B0"/></left><right style="thin"><color rgb="FFB0B0B0"/></right><top style="thin"><color rgb="FFB0B0B0"/></top><bottom style="thin"><color rgb="FFB0B0B0"/></bottom><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="8">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment vertical="top"/></xf>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    $safeName = substr(preg_replace('/[\[\]\*\?\/\\\\:]/', '-', $sheetName), 0, 31);
    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . xlsxEsc($safeName) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => $styles,
        'xl/worksheets/sheet1.xml' => $sheet,
    ];
    return xlsxZip($files);
}
