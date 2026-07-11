<?php

declare(strict_types=1);

/**
 * Gerador minimo de arquivos .xlsx (Office Open XML) sem dependencias
 * externas - usa apenas ZipArchive. Suporta multiplas planilhas (abas),
 * celulas de texto (inlineStr) e numericas, com auto-deteccao.
 *
 * Uso:
 *   $x = new XlsxWriter();
 *   $x->addSheet('Resumo', [['Coluna A','Coluna B'], ['texto', 123.45]]);
 *   $bytes = $x->build();  // string binaria do .xlsx
 */
class XlsxWriter
{
    /** @var array<int,array{name:string,rows:array}> */
    private array $sheets = [];

    /** Adiciona uma aba. $rows = array de linhas; cada linha = array de celulas. */
    public function addSheet(string $name, array $rows): void
    {
        $this->sheets[] = ['name' => $this->sanitizeName($name), 'rows' => $rows];
    }

    /** Monta o arquivo e retorna os bytes. */
    public function build(): string
    {
        if (!$this->sheets) {
            $this->addSheet('Planilha1', []);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());

        foreach ($this->sheets as $i => $sheet) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $this->sheetXml($sheet['rows']));
        }

        $zip->close();
        $bytes = (string)file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }

    private function contentTypes(): string
    {
        $overrides = '';
        foreach ($this->sheets as $i => $s) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1)
                . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . $overrides
            . '</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbook(): string
    {
        $sheetTags = '';
        foreach ($this->sheets as $i => $s) {
            $sheetTags .= '<sheet name="' . $this->esc($s['name']) . '" sheetId="' . ($i + 1)
                . '" r:id="rId' . ($i + 1) . '"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheetTags . '</sheets></workbook>';
    }

    private function workbookRels(): string
    {
        $rels = '';
        foreach ($this->sheets as $i => $s) {
            $rels .= '<Relationship Id="rId' . ($i + 1)
                . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
                . ' Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels . '</Relationships>';
    }

    private function sheetXml(array $rows): string
    {
        $body = '';
        $r = 0;
        foreach ($rows as $row) {
            $r++;
            $c = 0;
            $cells = '';
            foreach ($row as $value) {
                $c++;
                $ref = $this->colLetter($c) . $r;
                if (is_int($value) || is_float($value)) {
                    $cells .= '<c r="' . $ref . '" t="n"><v>' . $value . '</v></c>';
                } else {
                    $cells .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">'
                        . $this->esc((string)$value) . '</t></is></c>';
                }
            }
            $body .= '<row r="' . $r . '">' . $cells . '</row>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . $body . '</sheetData></worksheet>';
    }

    private function colLetter(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $n--;
            $s = chr(65 + ($n % 26)) . $s;
            $n = intdiv($n, 26);
        }
        return $s;
    }

    private function sanitizeName(string $name): string
    {
        $name = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', ' ', $name) ?? $name;
        $name = trim($name);
        if ($name === '') {
            $name = 'Planilha';
        }
        return mb_substr($name, 0, 31); // limite do Excel
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
