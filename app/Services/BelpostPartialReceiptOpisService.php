<?php

namespace App\Services;

use App\Models\MailBatch;
use App\Models\Order;
use App\Models\TenantSetting;
use Illuminate\Support\Collection;
use RuntimeException;

class BelpostPartialReceiptOpisService
{
    /**
     * @return array{binary: string, filename: string, content_type: string}
     */
    public function buildBatchDownload(MailBatch $batch, int $tenantId): array
    {
        if (!$batch->is_partial_receipt) {
            throw new RuntimeException('Опись доступна только для партии с получением части вложения.');
        }

        $orders = $this->eligibleOrders($batch);
        if ($orders->isEmpty()) {
            throw new RuntimeException(
                'Нет оформленных заявок с треком для описи. Сначала оформите бланки Белпочты.'
            );
        }

        $belpost   = new BelpostService($tenantId);
        $sender    = trim((string) TenantSetting::get('belpost_sender_name', ''));
        $builder   = new BelpostPartialReceiptOpisBuilder();
        $documents = [];

        foreach ($orders as $order) {
            $linesCheck = $belpost->buildPartialReceiptLines($order);
            if (!$linesCheck['ok']) {
                throw new RuntimeException($linesCheck['message']);
            }

            $track = trim((string) $order->track_number);
            if ($track === '') {
                continue;
            }

            $binary = $builder->build([
                'track'          => $track,
                'recipient_name' => (string) ($order->full_name ?? ''),
                'sender_name'    => $sender,
                'cod_rubles'     => $belpost->orderCashOnDeliveryRubles($order),
                'lines'          => $linesCheck['lines'],
            ]);

            $documents[] = [
                'filename' => $track . '_Opis_vlozheniya.docx',
                'binary'   => $binary,
            ];
        }

        if ($documents === []) {
            throw new RuntimeException(
                'Нет оформленных заявок с треком для описи. Сначала оформите бланки Белпочты.'
            );
        }

        if (count($documents) === 1) {
            $doc = $documents[0];

            return [
                'binary'       => $doc['binary'],
                'filename'     => $doc['filename'],
                'content_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ];
        }

        return [
            'binary'       => $this->zipDocuments($documents),
            'filename'     => 'belpost-' . $batch->batch_id . '-opis.zip',
            'content_type' => 'application/zip',
        ];
    }

    /**
     * @return Collection<int, Order>
     */
    public function eligibleOrders(MailBatch $batch): Collection
    {
        return Order::query()
            ->where('mail_batch_id', $batch->id)
            ->where('status', 'Оформлен')
            ->where('delivery_type', 'belpost')
            ->whereNotNull('track_number')
            ->where('track_number', '!=', '')
            ->orderBy('status_changed_at')
            ->get();
    }

    /**
     * @param  array<int, array{filename: string, binary: string}>  $documents
     */
    private function zipDocuments(array $documents): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'opiszip_');
        if ($tmp === false) {
            throw new RuntimeException('Не удалось создать архив описи');
        }

        $path = $tmp . '.zip';
        @unlink($tmp);

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Не удалось создать ZIP с описями');
        }

        foreach ($documents as $doc) {
            $zip->addFromString($doc['filename'], $doc['binary']);
        }
        $zip->close();

        $binary = file_get_contents($path);
        @unlink($path);

        if ($binary === false || $binary === '') {
            throw new RuntimeException('Пустой архив описи');
        }

        return $binary;
    }
}
