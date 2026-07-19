<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class MailBatch extends Model
{
    /**
     * Lifecycle statuses for a batch.
     * draft      → items being added
     * committed  → POST /commit sent, Belpost generating PDF
     * downloading → DownloadBelpostPdfJob dispatched
     * ready      → PDF downloaded and available
     * failed     → unrecoverable error
     */
    public const STATUS_DRAFT       = 'draft';
    public const STATUS_COMMITTED   = 'committed';
    public const STATUS_DOWNLOADING = 'downloading';
    public const STATUS_READY       = 'ready';
    public const STATUS_FAILED      = 'failed';

    /** Types where the seller always pays (cannot select «Покупатель»). */
    public const SELLER_ONLY_TYPES = ['ecommerce_light', 'ecommerce_optima'];

    /** Allowed values for the who_pays column. */
    public const WHO_PAYS_OPTIONS = ['Покупатель', 'Продавец'];

    /**
     * Belpost postal_delivery_type codes → human labels.
     * Keep in sync with Belpost API reference.
     */
    public const DELIVERY_TYPES = [
        'package'               => 'Простая посылка республиканская (без ОЦ)',
        'package_declare_value' => 'Посылка с объявленной ценностью',
        'ems'                   => 'Экспресс-посылка',
        'ecommerce_economical'  => 'E-commerce Эконом',
        'ecommerce_standard'    => 'E-commerce Стандарт',
        'ecommerce_elite'       => 'E-commerce Элит',
        'ecommerce_express'     => 'E-commerce Экспресс',
        'ecommerce_light'       => 'E-commerce Лайт',
        'ecommerce_optima'      => 'E-commerce Оптима',
    ];

    protected $fillable = [
        'tenant_id',
        'batch_id',
        'type',
        'who_pays',
        'status',
        'id_to_download',
        'pdf_path',
        'error_message',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isPdfReady(): bool
    {
        return $this->status === self::STATUS_READY && $this->pdf_path;
    }
}
