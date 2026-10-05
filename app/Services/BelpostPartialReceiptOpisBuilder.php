<?php

namespace App\Services;

/**
 * Builds {track}_Opis_vlozheniya.docx (Office Open XML via ZipArchive).
 */
class BelpostPartialReceiptOpisBuilder
{
    private const FOOTNOTE = '*1-не подошел размер, 2-не устраивает качество, 3-брак, 4-не нравится стиль, '
        . '5-отличается от описания на сайте, 6-другое (указать).';

    /**
     * @param  array{
     *   track: string,
     *   recipient_name: string,
     *   sender_name: string,
     *   cod_rubles: int,
     *   lines: array<int, array{article: string, name: string, quantity: int, price: float}>
     * }  $data
     */
    public function build(array $data): string
    {
        $documentXml = $this->buildDocumentXml($data);

        $tmp = tempnam(sys_get_temp_dir(), 'opis_');
        if ($tmp === false) {
            throw new \RuntimeException('Не удалось создать временный файл для описи');
        }

        $path = $tmp . '.docx';
        @unlink($tmp);

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Не удалось создать DOCX');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $zip->addFromString('_rels/.rels', $this->rootRelsXml());
        $zip->addFromString('word/_rels/document.xml.rels', $this->documentRelsXml());
        $zip->addFromString('word/document.xml', $documentXml);
        $zip->addFromString('word/styles.xml', $this->stylesXml());
        $zip->close();

        $binary = file_get_contents($path);
        @unlink($path);

        if ($binary === false || $binary === '') {
            throw new \RuntimeException('Пустой файл описи');
        }

        return $binary;
    }

    /**
     * @param  array{
     *   track: string,
     *   recipient_name: string,
     *   sender_name: string,
     *   cod_rubles: int,
     *   lines: array<int, array{article: string, name: string, quantity: int, price: float}>
     * }  $data
     */
    private function buildDocumentXml(array $data): string
    {
        $track         = (string) $data['track'];
        $recipient     = (string) $data['recipient_name'];
        $sender        = (string) $data['sender_name'];
        $cod           = (int) $data['cod_rubles'];
        $lines         = $data['lines'];
        $codFormatted  = number_format($cod, 2, ',', ' ');
        $codWords      = \App\Support\BelpostRublesInWords::format($cod, 0);

        $body = '';
        $body .= $this->paragraph(
            'Форма бланка для отправлений E-commerce с отметкой «получение части вложения»',
            true,
            'center'
        );
        $body .= $this->paragraph('Экземпляр РУП «Белпочта»', true);
        $body .= $this->paragraph('Почтовый денежный перевод наложенного платежа, ф. ПС112е.');
        $body .= $this->paragraph('Сумма: ' . $codFormatted . ' (' . $codWords . ')');
        $body .= $this->paragraph('Получатель перевода: ' . $sender);
        $body .= $this->paragraph('Перечень вложения, номер отправления ' . $track);
        $body .= $this->paragraph('Получатель отправления: ' . $recipient);
        $body .= $this->productTable($lines);
        $body .= $this->paragraph(self::FOOTNOTE);
        $body .= $this->paragraph('Выдал _________________________________');
        $body .= $this->paragraph('Дата вручения _________________________________');
        $body .= $this->cutLine();
        $body .= $this->paragraph('Экземпляр отправителя (интернет-магазина)', true);
        $body .= $this->paragraph('Перечень вложения, номер отправления ' . $track);
        $body .= $this->paragraph('Получатель отправления: ' . $recipient);
        $body .= $this->productTable($lines);
        $body .= $this->paragraph(self::FOOTNOTE);
        $body .= $this->paragraph(
            'Отказ от получения всех вложений (полный отказ): _________________________________ (подпись получателя)'
        );
        $body .= $this->paragraph('Работник почты:');
        $body .= $this->paragraph(
            'Возвращено отправителю непринятое вложение. Дата _________________________________ '
            . 'Подпись _________________________________'
        );

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body>' . $body . '<w:sectPr/></w:body></w:document>';
    }

    /**
     * @param  array<int, array{article: string, name: string, quantity: int, price: float}>  $lines
     */
    private function productTable(array $lines): string
    {
        $headers = [
            '№', 'Артикул', 'Наименование', 'Кол-во', 'Цена', 'Стоимость',
            'Подпись клиента в получении', 'Отметка клиента о возврате',
        ];

        $rows = '<w:tr>';
        foreach ($headers as $header) {
            $rows .= $this->tableCell($header, true);
        }
        $rows .= '</w:tr>';

        foreach ($lines as $index => $line) {
            $qty   = (int) $line['quantity'];
            $price = (float) $line['price'];
            $cost  = $qty * $price;
            $cells = [
                (string) ($index + 1),
                (string) $line['article'],
                (string) $line['name'],
                (string) $qty,
                $this->formatMoney($price),
                $this->formatMoney($cost),
                '',
                '',
            ];
            $rows .= '<w:tr>';
            foreach ($cells as $cell) {
                $rows .= $this->tableCell($cell, false);
            }
            $rows .= '</w:tr>';
        }

        return '<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/><w:tblBorders>'
            . '<w:top w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
            . '<w:left w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
            . '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
            . '<w:right w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
            . '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
            . '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
            . '</w:tblBorders></w:tblPr>' . $rows . '</w:tbl>';
    }

    private function tableCell(string $text, bool $bold): string
    {
        $run = $bold
            ? '<w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">' . $this->escape($text) . '</w:t></w:r>'
            : '<w:r><w:t xml:space="preserve">' . $this->escape($text) . '</w:t></w:r>';

        return '<w:tc><w:tcPr><w:tcW w:w="1200" w:type="dxa"/></w:tcPr><w:p>' . $run . '</w:p></w:tc>';
    }

    private function paragraph(string $text, bool $bold = false, ?string $align = null): string
    {
        $pPr = '';
        if ($align === 'center') {
            $pPr = '<w:pPr><w:jc w:val="center"/></w:pPr>';
        }

        $run = $bold
            ? '<w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">' . $this->escape($text) . '</w:t></w:r>'
            : '<w:r><w:t xml:space="preserve">' . $this->escape($text) . '</w:t></w:r>';

        return '<w:p>' . $pPr . $run . '</w:p>';
    }

    private function cutLine(): string
    {
        return '<w:p><w:pPr><w:jc w:val="center"/><w:pBdr><w:bottom w:val="single" w:sz="12" w:space="1" w:color="auto"/>'
            . '</w:pBdr><w:spacing w:before="240" w:after="240"/></w:pPr>'
            . '<w:r><w:t xml:space="preserve">— — — линия отреза — — —</w:t></w:r></w:p>';
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 2, ',', ' ');
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '</Types>';
    }

    private function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>';
    }

    private function documentRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman"/>'
            . '<w:sz w:val="22"/></w:rPr></w:rPrDefault></w:docDefaults>'
            . '</w:styles>';
    }
}
