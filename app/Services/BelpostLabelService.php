<?php

namespace App\Services;

use App\Models\MailBatch;
use App\Models\Order;
use App\Models\Product;
use App\Models\TenantSetting;
use App\Support\AppPdf;
use App\Support\Code128HtmlBarcode;
use App\Support\LabelSheetLayout;
use Illuminate\Support\Collection;
use Picqer\Barcode\BarcodeGeneratorPNG;
use RuntimeException;
use Throwable;

/**
 * Builds Belpost blanks in CRM (Dompdf + Code128). Does not call Belpost HTTP.
 */
class BelpostLabelService
{
    /**
     * @return array{binary: string, filename: string, labels: array, layout: LabelSheetLayout}
     */
    public function generate(MailBatch $batch, string $labelSize): array
    {
        $this->assertSenderFilled();

        $orders = $this->trackedOrders($batch);
        if ($orders->isEmpty()) {
            throw new RuntimeException('сначала оформите бланки');
        }

        $batch->update(['label_size' => $labelSize]);

        if ($orders->count() > 30) {
            set_time_limit(120);
        }

        $labels = $this->labelsForOrders($batch, $orders, $labelSize);
        $layout = LabelSheetLayout::forLabelSize($labelSize);
        $binary = $this->renderPdf($labels, $layout);

        return [
            'binary'   => $binary,
            'filename' => 'belpost-' . $batch->batch_id . '-labels.pdf',
            'labels'   => $labels,
            'layout'   => $layout,
        ];
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @return array<int, array<string, mixed>>
     */
    public function labelsForOrders(MailBatch $batch, Collection $orders, ?string $labelSize = null): array
    {
        $productWeights = $this->productWeights($orders);
        $senderName     = trim((string) TenantSetting::get('belpost_sender_name', ''));
        $senderStreet   = trim((string) TenantSetting::get('belpost_sender_street', ''));
        $senderPostcode = trim((string) TenantSetting::get('belpost_sender_postcode', ''));
        $senderCity     = trim((string) TenantSetting::get('belpost_sender_city', ''));
        $contractNo     = trim((string) TenantSetting::get('belpost_contract_no', ''));
        $contractDate   = trim((string) TenantSetting::get('belpost_contract_date', ''));
        $shelfLife      = (string) (TenantSetting::get('shelf_life', '10') ?: '10');
        $packageTitle   = MailBatch::blankTypeLabel($batch->type);
        $isEcommerce    = str_contains((string) $batch->type, 'ecommerce');
        $recipientPays  = ($batch->who_pays ?? '') === 'Покупатель';
        $barcodeSize    = $this->barcodeSizeFor($labelSize);

        $labels = [];
        foreach ($orders as $order) {
            $track = trim((string) $order->track_number);
            $cityParts = $this->splitCityPostcode((string) ($order->city ?? ''));

            $cod = $this->codAmount($order);

            $labels[] = [
                'sender_name'             => $senderName,
                'sender_street'           => $senderStreet,
                'sender_postcode'         => $senderPostcode,
                'sender_city'             => $senderCity,
                'package_title'           => $packageTitle,
                'contract_no'             => $contractNo,
                'contract_date'           => $contractDate,
                'cod_rubles'              => $cod['rubles'],
                'cod_kopecks'             => $cod['kopecks'],
                'recipient_name'          => (string) $order->full_name,
                'recipient_street'        => $this->recipientStreet($order),
                'recipient_postcode'      => $cityParts['postcode'],
                'recipient_city'          => $cityParts['city'],
                'recipient_phone'         => (string) ($order->phone ?? ''),
                'weight_text'             => $this->formatWeightGrams(
                    BelpostService::sumGoodsWeightGrams(
                        $order->goods ?? [],
                        $order->quantities ?? [],
                        $productWeights
                    )
                ),
                'show_recipient_pays'     => $recipientPays,
                'show_electronic_notice'  => $isEcommerce,
                'show_ecommerce_logo'     => $isEcommerce,
                'ecommerce_logo_path'     => $this->ecommerceLogoPath($isEcommerce),
                'shelf_life'              => $shelfLife,
                'track_number'            => $track,
                'barcode_markup'          => $this->barcodeMarkup(
                    $track,
                    $barcodeSize['module'],
                    $barcodeSize['height']
                ),
            ];
        }

        return $labels;
    }

    /**
     * @return Collection<int, Order>
     */
    public function trackedOrders(MailBatch $batch): Collection
    {
        return $batch->orders()
            ->whereNotNull('track_number')
            ->where('track_number', '!=', '')
            ->orderBy('id')
            ->get();
    }

    private function assertSenderFilled(): void
    {
        $name = trim((string) TenantSetting::get('belpost_sender_name', ''));
        if ($name === '') {
            throw new RuntimeException('заполните отправителя в Настройках');
        }
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @return array<string, float|int>
     */
    private function productWeights(Collection $orders): array
    {
        $names = [];
        foreach ($orders as $order) {
            foreach ($order->goods ?? [] as $name) {
                $trimmed = trim((string) $name);
                if ($trimmed !== '') {
                    $names[$trimmed] = true;
                }
            }
        }

        if ($names === []) {
            return [];
        }

        return Product::query()
            ->whereIn('name', array_keys($names))
            ->pluck('weight', 'name')
            ->all();
    }

    /**
     * @return array{postcode: string, city: string}
     */
    private function splitCityPostcode(string $city): array
    {
        $city = trim($city);
        $postcode = '';
        if (preg_match('/\b(\d{6})\b/', $city, $match)) {
            $postcode = $match[1];
            $city = trim(preg_replace('/\b' . preg_quote($postcode, '/') . '\b/', '', $city));
            $city = trim($city, " \t,");
        }

        return [
            'postcode' => $postcode,
            'city'     => $city,
        ];
    }

    private function recipientStreet(Order $order): string
    {
        $parts = [];
        $street = trim((string) ($order->street ?? ''));
        if ($street !== '') {
            $parts[] = $street;
        }
        $building = trim((string) ($order->building ?? ''));
        if ($building !== '') {
            $parts[] = 'д. ' . $building;
        }
        $housing = trim((string) ($order->housing ?? ''));
        if ($housing !== '') {
            $parts[] = 'корп. ' . $housing;
        }
        $apartment = trim((string) ($order->apartment ?? ''));
        if ($apartment !== '') {
            $parts[] = 'кв. ' . $apartment;
        }

        return implode(', ', $parts);
    }

    /**
     * @return array{rubles: int, kopecks: string}
     */
    private function codAmount(Order $order): array
    {
        $goods      = $order->goods ?? [];
        $quantities = $order->quantities ?? [];
        $prices     = $order->prices ?? [];
        $total      = 0.0;

        foreach ($goods as $i => $goodName) {
            $qty   = (int) ($quantities[$i] ?? 1);
            $price = (float) ($prices[$i] ?? 0);
            $total += $qty * $price;
        }

        $kopecksTotal = (int) round($total * 100);
        $rubles       = intdiv($kopecksTotal, 100);
        $kopecks      = $kopecksTotal % 100;

        return [
            'rubles'  => $rubles,
            'kopecks' => sprintf('%02d', $kopecks),
        ];
    }

    private function ecommerceLogoPath(bool $show): string
    {
        if (!$show) {
            return '';
        }

        // Dompdf needs GD/Imagick to embed PNG. Prefer SVG so blanks still print
        // on hosts without php-gd (CLI tests, lean PHP-FPM).
        $svg = public_path('images/belpost-ecommerce.svg');
        if (is_file($svg)) {
            return $svg;
        }

        $canRaster = extension_loaded('gd') || extension_loaded('imagick');
        $png = public_path('images/belpost-ecommerce.png');
        if ($canRaster && is_file($png)) {
            return $png;
        }

        return '';
    }

    private function formatWeightGrams(int $grams): string
    {
        return 'Вес ___________';
    }

    /**
     * @return array{module: string, height: string}
     */
    private function barcodeSizeFor(?string $labelSize): array
    {
        if ($labelSize === '120x80') {
            return ['module' => '0.16', 'height' => '14'];
        }
        if ($labelSize === '210x150') {
            return ['module' => '0.28', 'height' => '16'];
        }

        return ['module' => '0.24', 'height' => '12'];
    }

    private function barcodeMarkup(string $track, string $moduleMm = '0.24', string $heightMm = '11'): string
    {
        $canRaster = (extension_loaded('imagick') || extension_loaded('gd'))
            && class_exists(BarcodeGeneratorPNG::class);

        if ($canRaster) {
            try {
                $generator = new BarcodeGeneratorPNG();
                if (!extension_loaded('imagick') && method_exists($generator, 'useGd')) {
                    $generator->useGd();
                }
                $scale = max(1, (int) round((float) $moduleMm / 0.12));
                $pngH  = max(30, (int) round((float) $heightMm * 3.5));
                $png = $generator->getBarcode($track, BarcodeGeneratorPNG::TYPE_CODE_128, $scale, $pngH);

                return '<img src="data:image/png;base64,' . base64_encode($png) . '" alt="barcode">';
            } catch (Throwable $e) {
                // Table barcode below — Picqer PNG needs a working GD/Imagick.
            }
        }

        return Code128HtmlBarcode::render($track, $moduleMm, $heightMm);
    }

    /**
     * @param  array<int, array<string, mixed>>  $labels
     */
    private function renderPdf(array $labels, LabelSheetLayout $layout): string
    {
        $pdf = AppPdf::loadView('pdf.belpost-labels-sheet', [
            'labels' => $labels,
            'layout' => $layout,
            'pages'  => array_chunk($labels, $layout->perPage()),
        ]);

        if ($layout->usesA4()) {
            $pdf->setPaper('a4', 'portrait');
        } else {
            $wPt = $layout->sheetWidthMm() * 72 / 25.4;
            $hPt = $layout->sheetHeightMm() * 72 / 25.4;
            $pdf->setPaper([0, 0, $wPt, $hPt], 'portrait');
        }

        return $pdf->output(['compress' => 0]);
    }
}
