<?php

namespace App\Jobs;

use App\Models\MailBatch;
use App\Models\TenantSetting;
use App\Services\BelpostService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DownloadBelpostPdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum attempts before marking as failed.
     */
    public int $tries = 3;

    /**
     * Seconds between retries (exponential: 60, 120, 240 s).
     */
    public array $backoff = [60, 120, 240];

    /**
     * Allow up to 3 minutes per attempt (ZIP download can be slow).
     */
    public int $timeout = 180;

    private int $batchId;
    private int $tenantId;

    public function __construct(int $batchId, int $tenantId)
    {
        $this->batchId  = $batchId;
        $this->tenantId = $tenantId;
    }

    public function handle(): void
    {
        /** @var MailBatch|null $batch */
        $batch = MailBatch::withoutGlobalScopes()->find($this->batchId);

        if (!$batch) {
            Log::error('DownloadBelpostPdfJob: batch not found', ['batch_id' => $this->batchId]);
            return;
        }

        if (!$batch->id_to_download) {
            Log::error('DownloadBelpostPdfJob: no id_to_download', ['batch_id' => $this->batchId]);
            $batch->update([
                'status'        => MailBatch::STATUS_FAILED,
                'error_message' => 'Нет id_to_download — commit не завершён корректно',
            ]);
            return;
        }

        $batch->update(['status' => MailBatch::STATUS_DOWNLOADING]);

        // Bootstrap tenant context so TenantSetting::get() works
        $this->setTenantContext($batch->tenant_id);

        try {
            $service = new BelpostService($batch->tenant_id);
            $zipPath = $service->downloadDocuments($batch->id_to_download, $batch->batch_id);

            $batch->update([
                'status'   => MailBatch::STATUS_READY,
                'pdf_path' => $zipPath,
                'error_message' => null,
            ]);

            Log::info('DownloadBelpostPdfJob: done', ['batch_id' => $this->batchId, 'path' => $zipPath]);
        } catch (\Throwable $e) {
            Log::error('DownloadBelpostPdfJob: failed', [
                'batch_id' => $this->batchId,
                'error'    => $e->getMessage(),
            ]);

            $batch->update([
                'status'        => MailBatch::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('DownloadBelpostPdfJob permanently failed', [
            'batch_id' => $this->batchId,
            'error'    => $exception->getMessage(),
        ]);

        MailBatch::withoutGlobalScopes()
            ->where('id', $this->batchId)
            ->update([
                'status'        => MailBatch::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
            ]);
    }

    /**
     * Laravel's TenantScope reads the tenant from the authenticated session.
     * For queue workers there is no session, so we set the tenant_id
     * on the auth-less TenantSetting model manually via a fake session or
     * simply bypass the scope with withoutGlobalScopes() in BelpostService.
     *
     * Here we just store the tenantId in the app container so that
     * TenantSetting::get() (which uses the global scope) can resolve it.
     */
    private function setTenantContext(int $tenantId): void
    {
        app()->instance('current_tenant_id', $tenantId);
    }
}
