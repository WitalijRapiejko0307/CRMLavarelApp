<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SMS_SEGMENT_PATTERN = '/^\d{2}\.\d{2}\.\d{4} - (об отправке|в отделении|5 день|10 день)$/u';

    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->text('comment')->nullable()->after('source');
            $table->text('upsell')->nullable()->after('comment');
            $table->text('cross_sell')->nullable()->after('upsell');
        });

        DB::table('orders')
            ->whereNotNull('sms_log')
            ->where('sms_log', '!=', '')
            ->orderBy('id')
            ->chunkById(100, function ($orders) {
                foreach ($orders as $order) {
                    $split = $this->splitLegacySmsLog($order->sms_log);

                    DB::table('orders')
                        ->where('id', $order->id)
                        ->update([
                            'comment' => $split['comment'],
                            'sms_log' => $split['sms_log'],
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['comment', 'upsell', 'cross_sell']);
        });
    }

    /**
     * @return array{comment: ?string, sms_log: ?string}
     */
    private function splitLegacySmsLog(?string $smsLog): array
    {
        if ($smsLog === null || trim($smsLog) === '') {
            return ['comment' => null, 'sms_log' => null];
        }

        $smsParts     = [];
        $commentParts = [];

        foreach (explode(', ', $smsLog) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            if (preg_match(self::SMS_SEGMENT_PATTERN, $segment)) {
                $smsParts[] = $segment;
            } else {
                $commentParts[] = $segment;
            }
        }

        return [
            'comment' => $commentParts !== [] ? implode(', ', $commentParts) : null,
            'sms_log' => $smsParts !== [] ? implode(', ', $smsParts) : null,
        ];
    }
};
