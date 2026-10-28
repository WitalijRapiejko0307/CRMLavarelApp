<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retires the legacy «Посчитан» order status (nightly auto-transition from «Завершен»).
 * Orders and history are rewritten to «Завершен» via the query builder only so OrderObserver
 * does not run and income/stock side effects stay unchanged. Spurious Завершен→Завершен
 * history rows are removed so daily buyout digests are not double-counted.
 */
return new class extends Migration
{
    private const OLD_STATUS = 'Посчитан';

    private const NEW_STATUS = 'Завершен';

    private const BACKUP_TABLE = 'poschitan_status_backup';

    public function up(): void
    {
        if (Schema::hasTable(self::BACKUP_TABLE)) {
            Schema::dropIfExists(self::BACKUP_TABLE);
        }

        Schema::create(self::BACKUP_TABLE, function (Blueprint $table) {
            $table->id();
            $table->string('source', 32);
            $table->unsignedBigInteger('row_id');
            $table->json('payload');
            $table->timestamp('created_at')->nullable();
        });

        DB::transaction(function () {
            DB::table('orders')
                ->where('status', self::OLD_STATUS)
                ->orderBy('id')
                ->chunkById(500, function ($rows) {
                    $inserts = [];
                    $now = now();
                    foreach ($rows as $row) {
                        $inserts[] = [
                            'source'     => 'orders',
                            'row_id'     => $row->id,
                            'payload'    => json_encode(['status' => self::OLD_STATUS], JSON_UNESCAPED_UNICODE),
                            'created_at' => $now,
                        ];
                    }
                    if ($inserts !== []) {
                        DB::table(self::BACKUP_TABLE)->insert($inserts);
                    }
                });

            DB::table('order_status_history')
                ->where(function ($query) {
                    $query->where('from_status', self::OLD_STATUS)
                        ->orWhere('to_status', self::OLD_STATUS);
                })
                ->orderBy('id')
                ->chunkById(500, function ($rows) {
                    $inserts = [];
                    $now = now();
                    foreach ($rows as $row) {
                        $inserts[] = [
                            'source'     => 'order_status_history',
                            'row_id'     => $row->id,
                            'payload'    => json_encode([
                                'from_status' => $row->from_status,
                                'to_status'   => $row->to_status,
                            ], JSON_UNESCAPED_UNICODE),
                            'created_at' => $now,
                        ];
                    }
                    if ($inserts !== []) {
                        DB::table(self::BACKUP_TABLE)->insert($inserts);
                    }
                });

            DB::table('orders')
                ->where('status', self::OLD_STATUS)
                ->update(['status' => self::NEW_STATUS]);

            DB::table('order_status_history')
                ->where('to_status', self::OLD_STATUS)
                ->update(['to_status' => self::NEW_STATUS]);

            DB::table('order_status_history')
                ->where('from_status', self::OLD_STATUS)
                ->update(['from_status' => self::NEW_STATUS]);

            DB::table('order_status_history')
                ->where('from_status', self::NEW_STATUS)
                ->where('to_status', self::NEW_STATUS)
                ->delete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::BACKUP_TABLE)) {
            return;
        }

        DB::transaction(function () {
            $orderBackups = DB::table(self::BACKUP_TABLE)
                ->where('source', 'orders')
                ->orderBy('id')
                ->get();

            foreach ($orderBackups as $backup) {
                $payload = json_decode($backup->payload, true);
                DB::table('orders')
                    ->where('id', $backup->row_id)
                    ->update(['status' => $payload['status']]);
            }

            $historyBackups = DB::table(self::BACKUP_TABLE)
                ->where('source', 'order_status_history')
                ->orderBy('id')
                ->get();

            foreach ($historyBackups as $backup) {
                $payload = json_decode($backup->payload, true);
                DB::table('order_status_history')
                    ->where('id', $backup->row_id)
                    ->update([
                        'from_status' => $payload['from_status'],
                        'to_status'   => $payload['to_status'],
                    ]);
            }

            // Rows deleted in up() as Завершен→Завершен (nightly noise) are not recreated on rollback.
        });

        Schema::dropIfExists(self::BACKUP_TABLE);
    }
};
