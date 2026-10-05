<?php

namespace App\Support;

/**
 * Loads barryvdh/laravel-dompdf and deps when Composer autoload was not rebuilt
 * (typical on hoster.by without a composer binary).
 */
class PdfVendorLoader
{
    public static function register(string $vendorDir): void
    {
        $vendorDir = rtrim($vendorDir, '/');

        if (class_exists('Barryvdh\\DomPDF\\Facade\\Pdf', true)) {
            return;
        }

        if (!is_dir($vendorDir . '/barryvdh/laravel-dompdf/src')) {
            return;
        }

        spl_autoload_register(static function ($class) use ($vendorDir) {
            $extra = [
                'Dompdf\\Cpdf' => $vendorDir . '/dompdf/dompdf/lib/Cpdf.php',
            ];
            if (isset($extra[$class]) && is_file($extra[$class])) {
                require $extra[$class];

                return;
            }

            $prefixes = [
                'Barryvdh\\DomPDF\\' => $vendorDir . '/barryvdh/laravel-dompdf/src/',
                'Dompdf\\'           => $vendorDir . '/dompdf/dompdf/src/',
                'FontLib\\'          => $vendorDir . '/phenx/php-font-lib/src/FontLib/',
                'Svg\\'              => $vendorDir . '/phenx/php-svg-lib/src/Svg/',
                'Sabberworm\\CSS\\'  => $vendorDir . '/sabberworm/php-css-parser/src/',
                'Picqer\\Barcode\\'  => $vendorDir . '/picqer/php-barcode-generator/src/',
            ];

            foreach ($prefixes as $prefix => $dir) {
                if (strpos($class, $prefix) !== 0) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                $path = $dir . $relative;
                if (is_file($path)) {
                    require $path;
                }

                return;
            }
        });
    }
}
