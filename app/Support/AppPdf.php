<?php

namespace App\Support;

use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\ServiceProvider as DomPdfServiceProvider;
use RuntimeException;

/**
 * Boots Dompdf only when a PDF is actually generated — never on every HTTP request.
 */
class AppPdf
{
    /**
     * @param  array<string, mixed>  $data
     * @return \Barryvdh\DomPDF\PDF|\Barryvdh\DomPDF\Facade\Pdf
     */
    public static function loadView(string $view, array $data = [], array $mergeData = [])
    {
        self::boot();

        return Pdf::loadView($view, $data, $mergeData);
    }

    public static function boot(): void
    {
        PdfVendorLoader::register(base_path('vendor'));

        if (!class_exists(Pdf::class)) {
            throw new RuntimeException(
                'PDF-библиотека не установлена. В ~/crm распакуйте pdf-vendor-dropin.tar.gz'
            );
        }

        $app = app();
        if (!$app->bound('dompdf.wrapper')) {
            $app->register(DomPdfServiceProvider::class);
        }
    }
}
