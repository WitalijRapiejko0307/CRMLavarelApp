<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrdersTable extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // GAS col A — внешний ID (SalesRender или другой источник)
            $table->string('external_id')->nullable()->index();

            // GAS col C
            $table->string('full_name');

            // GAS col D — статус
            $table->string('status', 50)->default('Позвонить')->index();

            // GAS col E — дата изменения статуса
            $table->timestamp('status_changed_at')->nullable();

            // GAS col F — товары (JSON массив строк)
            $table->json('goods')->nullable();

            // GAS col G — количества (JSON массив int)
            $table->json('quantities')->nullable();

            // GAS cols H–L — адрес
            $table->string('city')->nullable();
            $table->string('street')->nullable();
            $table->string('building', 20)->nullable();
            $table->string('housing', 20)->nullable();
            $table->string('apartment', 20)->nullable();

            // GAS col M — источник заявки
            $table->string('source', 50)->nullable()->default('site');

            // GAS col N — телефон
            $table->string('phone', 20)->nullable()->index();

            // GAS col O — цены (JSON массив float)
            $table->json('prices')->nullable();

            // GAS col R — трек-номер Белпочта/Европочта
            $table->string('track_number', 50)->nullable()->index();

            // GAS col S — тип доставки
            $table->enum('delivery_type', ['belpost', 'europochta'])->nullable();

            // GAS col T — лог SMS
            $table->text('sms_log')->nullable();

            // ID заявки в call-центре (SalesRender)
            $table->string('ops_id')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
}
