<?php

namespace App\Services;

use App\Exceptions\BelpostLabelException;
use App\Models\BelpostLabelLog;
use App\Models\MailBatch;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records every blank / opis generation attempt (DB + `belpost_labels` log channel).
 * Never throws: logging must not break the download.
 */
class BelpostLabelLogger
{
    public function success(MailBatch $batch, int $userId, string $kind, ?string $labelSize, int $ordersCount, float $startedAt): void
    {
        $this->write($batch, $userId, $kind, $labelSize, $ordersCount, BelpostLabelLog::RESULT_SUCCESS, null, null, $startedAt);
    }

    /**
     * @return string error code returned to the UI
     */
    public function failure(MailBatch $batch, int $userId, string $kind, ?string $labelSize, Throwable $e, float $startedAt): string
    {
        $code = $e instanceof BelpostLabelException ? $e->errorCode() : BelpostLabelException::UNKNOWN;

        $this->write($batch, $userId, $kind, $labelSize, null, BelpostLabelLog::RESULT_ERROR, $code, $e->getMessage(), $startedAt, $e);

        return $code;
    }

    private function write(
        MailBatch $batch,
        int $userId,
        string $kind,
        ?string $labelSize,
        ?int $ordersCount,
        string $result,
        ?string $code,
        ?string $message,
        float $startedAt,
        ?Throwable $e = null
    ): void {
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        try {
            $context = [
                'tenant_id'  => $batch->tenant_id,
                'user_id'    => $userId,
                'batch_id'   => $batch->id,
                'kind'       => $kind,
                'label_size' => $labelSize,
                'orders'     => $ordersCount,
                'result'     => $result,
                'code'       => $code,
                'duration_ms' => $durationMs,
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            ];
            if ($e) {
                $context['exception'] = get_class($e);
                $context['error']     = $e->getMessage();
            }
            Log::channel('belpost_labels')->{$result === BelpostLabelLog::RESULT_ERROR ? 'error' : 'info'}('belpost.labels', $context);
        } catch (Throwable) {
            // ignore
        }

        try {
            BelpostLabelLog::create([
                'tenant_id'     => $batch->tenant_id,
                'mail_batch_id' => $batch->id,
                'user_id'       => $userId ?: null,
                'kind'          => $kind,
                'label_size'    => $labelSize,
                'orders_count'  => $ordersCount,
                'result'        => $result,
                'error_code'    => $code,
                'error_message' => $message !== null ? mb_substr($message, 0, 500) : null,
                'duration_ms'   => $durationMs,
            ]);
        } catch (Throwable $dbError) {
            Log::warning('BelpostLabelLogger: DB write failed', ['error' => $dbError->getMessage()]);
        }
    }
}
