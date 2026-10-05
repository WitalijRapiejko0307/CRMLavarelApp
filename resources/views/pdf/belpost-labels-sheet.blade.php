@php
    /** @var \App\Support\LabelSheetLayout $layout */
    $gapMm   = $layout->gapMm();
    $cols    = max(1, $layout->cols());
    $sizeKey = $layout->sizeKey();
@endphp
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <style>
        {!! $layout->css() !!}
        html, body {
            margin: 0;
            padding: 0;
            font-family: DejaVu Sans, sans-serif;
            font-size: 8pt;
            color: #111;
        }
        .label-sheet {
            box-sizing: border-box;
            width: {{ $layout->sheetWidthMm() }}mm;
        }
        .label-grid {
            width: 100%;
            border-collapse: collapse;
            border-spacing: 0;
        }
        .label-grid td.slot {
            vertical-align: top;
            padding: 0 {{ $gapMm }}mm {{ $gapMm }}mm 0;
        }
        .label {
            overflow: hidden;
            @if ($layout->drawsOuterBorder())
            border: 0.35mm solid #111;
            @endif
        }
        .label-card {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .label-card td {
            vertical-align: top;
            padding: 0;
        }
        .barcode-box td {
            padding: 0 !important;
            border: 0 !important;
        }
        .col-left {
            width: 51%;
        }
        .col-right {
            width: 49%;
            overflow: hidden;
        }
        .row-head .col-left {
            padding: 3.2mm 2mm 0 3.4mm;
        }
        .row-head .col-right {
            padding: 3.2mm 3.4mm 0 1.6mm;
        }
        .row-brand .col-left {
            padding: 13.5mm 2mm 2.5mm 3.4mm;
        }
        .row-brand .col-right {
            padding: 0;
        }
        .row-body .col-left {
            padding: 0 2mm 2mm 3.4mm;
        }
        .row-body .col-right {
            padding: 4mm 6mm 2mm 0.8mm;
        }
        .from-name,
        .to-name {
            font-weight: bold;
        }
        .brand-row {
            margin: 0;
            table-layout: fixed;
            border-collapse: separate;
            border-spacing: 2.2mm 0;
        }
        .brand-pair {
            width: 100%;
        }
        .brand-logo,
        .brand-type {
            box-sizing: border-box;
            border: 0.35mm solid #111;
            width: 34mm;
            height: 12mm;
            overflow: hidden;
            vertical-align: middle;
            text-align: center;
            padding: 0;
        }
        .brand-logo img {
            display: block;
            height: 10mm;
            width: auto;
            max-width: 32mm;
            max-height: 10mm;
            margin: 1mm auto 0 auto;
        }
        .brand-type {
            font-size: 8pt;
            padding: 0 1mm;
            white-space: nowrap;
        }
        .weight {
            margin: 0 0 1mm 0;
        }
        .tick {
            line-height: 1.35;
        }
        .contract,
        .cod,
        .shelf {
            line-height: 1.3;
        }
        .barcode-box {
            box-sizing: border-box;
            width: 64mm;
            height: 18mm;
            margin: 0 0 2mm 0;
            padding: 1.2mm 1.6mm 0.5mm 1.6mm;
            border: 0.3mm dashed #111;
            text-align: center;
            overflow: hidden;
        }
        .barcode-box img,
        .barcode-box svg {
            width: 96%;
            max-width: 96%;
            height: 12mm;
        }
        .barcode-box .barcode {
            border-collapse: collapse;
            border-spacing: 0;
            margin: 0 auto;
            height: 12mm;
            max-width: 100%;
        }
        .barcode-box .barcode td {
            padding: 0;
            border: 0;
        }
        .track {
            font-size: 7.5pt;
            letter-spacing: 0.25mm;
            margin-top: 0.3mm;
        }
        .to-block {
            margin-top: 1mm;
        }

        .label-size-210x150 {
            font-size: 9pt;
        }
        .label-size-210x150 .col-left {
            width: 51%;
        }
        .label-size-210x150 .col-right {
            width: 49%;
        }
        .label-size-210x150 .row-head .col-left {
            padding: 3.6mm 2.5mm 0 4.5mm;
        }
        .label-size-210x150 .row-head .col-right {
            padding: 3.6mm 4.5mm 0 2mm;
        }
        .label-size-210x150 .row-brand .col-left {
            padding: 30mm 1mm 3mm 4.5mm;
        }
        .label-size-210x150 .row-body .col-left {
            padding: 0 2.5mm 2.5mm 4.5mm;
        }
        .label-size-210x150 .row-body .col-right {
            padding: 4mm 8mm 2.5mm 1mm;
        }
        .label-size-210x150 .from-name,
        .label-size-210x150 .to-name {
            font-size: 11pt;
        }
        .label-size-210x150 .brand-pair {
            width: 100%;
        }
        .label-size-210x150 .brand-row {
            border-spacing: 2.2mm 0;
        }
        .label-size-210x150 .brand-logo,
        .label-size-210x150 .brand-type {
            width: 48mm;
            height: 16mm;
        }
        .label-size-210x150 .brand-logo img {
            height: 14mm;
            max-width: 46mm;
            max-height: 14mm;
            margin-top: 1mm;
        }
        .label-size-210x150 .brand-type {
            font-size: 9pt;
            padding: 0 2mm;
        }
        .label-size-210x150 .barcode-box {
            width: 88mm;
            height: 25mm;
            padding: 2mm 2.2mm 0.8mm 2.2mm;
            margin-bottom: 2.5mm;
        }
        .label-size-210x150 .barcode-box img,
        .label-size-210x150 .barcode-box svg,
        .label-size-210x150 .barcode-box .barcode {
            height: 17mm;
        }

        .label-size-150x100 {
            font-size: 8pt;
        }
        .label-size-150x100 .from-name,
        .label-size-150x100 .to-name {
            font-size: 9.5pt;
        }
        .label-size-150x100 .row-brand .col-left {
            padding: 13.5mm 0.4mm 2.5mm 3.2mm;
        }
        .label-size-150x100 .contract {
            white-space: nowrap;
        }

        .label-size-120x80 {
            font-size: 7pt;
        }
        .label-size-120x80 .col-left,
        .label-size-120x80 .col-right {
            width: 50%;
        }
        .label-size-120x80 .row-head .col-left {
            padding: 2mm 1.4mm 0 2.1mm;
        }
        .label-size-120x80 .row-head .col-right {
            padding: 2mm 2.1mm 0 1.2mm;
        }
        .label-size-120x80 .row-brand .col-left {
            padding: 6mm 1.4mm 1.6mm 2.1mm;
        }
        .label-size-120x80 .row-body .col-left {
            padding: 0 1.4mm 1.2mm 2.1mm;
        }
        .label-size-120x80 .row-body .col-right {
            padding: 1.5mm 8mm 1.2mm 1.5mm;
        }
        .label-size-120x80 .from-name,
        .label-size-120x80 .to-name {
            font-size: 8pt;
        }
        .label-size-120x80 .brand-pair {
            width: 100%;
        }
        .label-size-120x80 .brand-row {
            border-spacing: 1.1mm 0;
        }
        .label-size-120x80 .brand-logo,
        .label-size-120x80 .brand-type {
            width: 27mm;
            height: 9mm;
        }
        .label-size-120x80 .brand-logo img {
            height: 7mm;
            max-width: 25mm;
            max-height: 7mm;
            margin-top: 0.8mm;
        }
        .label-size-120x80 .brand-type {
            font-size: 6.5pt;
            padding: 0 0.6mm;
        }
        .label-size-120x80 .barcode-box {
            width: 40mm;
            height: 20mm;
            padding: 1.8mm 1.4mm 0.5mm 1.4mm;
            margin-bottom: 1.2mm;
        }
        .label-size-120x80 .barcode-box img,
        .label-size-120x80 .barcode-box svg,
        .label-size-120x80 .barcode-box .barcode {
            height: 14mm;
        }
        .label-size-120x80 .track {
            font-size: 6.5pt;
        }
        .label-size-120x80 .cod {
            white-space: nowrap;
        }
    </style>
</head>
<body>
@foreach ($pages as $pageIndex => $pageLabels)
    <div class="label-sheet"@if ($pageIndex < count($pages) - 1) style="page-break-after: always;"@endif>
        <table class="label-grid">
            @foreach (array_chunk($pageLabels, $cols) as $row)
                <tr>
                    @foreach ($row as $label)
                        <td class="slot">
                            <div class="label label-size-{{ $sizeKey }}">
                                @include('pdf.belpost-label', ['label' => $label])
                            </div>
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </table>
    </div>
@endforeach
</body>
</html>
