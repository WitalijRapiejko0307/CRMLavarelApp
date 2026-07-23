<?php

namespace App\Support;

use Generator;
use RuntimeException;

class CsvOrderReader
{
    private const ALIAS_MAP = [
        'id'                                      => 'external_id',
        'external_id'                             => 'external_id',
        '№ п/п'                                   => 'external_id',
        'no'                                      => 'external_id',
        'номер'                                   => 'external_id',
        'created_at'                              => 'created_at',
        'дата'                                    => 'created_at',
        'дата создания'                           => 'created_at',
        'full_name'                               => 'full_name',
        'фио'                                     => 'full_name',
        'имя'                                     => 'full_name',
        'status'                                  => 'status',
        'статус'                                  => 'status',
        'goods'                                   => 'goods',
        'товары'                                  => 'goods',
        'товар'                                   => 'goods',
        'quantities'                              => 'quantities',
        'количество'                              => 'quantities',
        'кол-во'                                  => 'quantities',
        'штук'                                    => 'quantities',
        'city'                                    => 'city',
        'город'                                   => 'city',
        'белпочта - город, район'                 => 'city',
        'street'                                  => 'street',
        'улица'                                   => 'street',
        'белпочта - название улицы, номер дома' => 'street',
        'building'                                => 'building',
        'дом'                                     => 'building',
        'housing'                                 => 'housing',
        'корпус'                                  => 'housing',
        'кор.'                                    => 'housing',
        'apartment'                               => 'apartment',
        'кв'                                      => 'apartment',
        'квартира'                                => 'apartment',
        'кв.'                                     => 'apartment',
        'phone'                                   => 'phone',
        'телефон'                                 => 'phone',
        'prices'                                  => 'prices',
        'цены'                                    => 'prices',
        'цена'                                    => 'prices',
        'цена за ед.'                             => 'prices',
        'track_number'                            => 'track_number',
        'трек'                                    => 'track_number',
        'трек номер'                              => 'track_number',
        'track'                                   => 'track_number',
        'delivery_type'                           => 'delivery_type',
        'вид доставки'                            => 'delivery_type',
        'source'                                  => 'source',
        'источник'                                => 'source',
        'сайт'                                    => 'source',
    ];

    private const DELIVERY_TYPE_MAP = [
        'белпочта'   => 'belpost',
        'belpost'    => 'belpost',
        'европочта'  => 'europochta',
        'europochta' => 'europochta',
        'курьер'     => 'courier',
        'courier'    => 'courier',
        'самовывоз'  => 'pickup',
        'самомвывоз' => 'pickup',
        'pickup'     => 'pickup',
        'лично'      => 'personal',
        'personal'   => 'personal',
    ];

    /**
     * @return Generator<int, array{rowNum: int, fields: array<string, string>}>
     */
    public static function read(string $path): Generator
    {
        $handle = fopen($path, 'r');
        if (!$handle) {
            throw new RuntimeException('Не удалось открыть файл');
        }

        $delimiter = self::detectDelimiter($handle);
        [$colMap, $headerRowNum] = self::findHeaderAndBuildMap($handle, $delimiter);

        if ($colMap === []) {
            fclose($handle);
            throw new RuntimeException('Не найдена строка заголовков (ФИО, Товар)');
        }

        $rowNum = $headerRowNum;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNum++;

            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $fields = [];
            foreach ($colMap as $idx => $field) {
                $value = isset($row[$idx]) ? trim((string) $row[$idx]) : null;

                if ($value === '' || $value === null) {
                    continue;
                }

                if ($field === 'delivery_type') {
                    $fields[$field] = self::mapDeliveryType($value) ?? $value;
                    continue;
                }

                $fields[$field] = $value;
            }

            yield [
                'rowNum' => $rowNum,
                'fields' => $fields,
            ];
        }

        fclose($handle);
    }

    public static function mapDeliveryType(string $value): ?string
    {
        return self::DELIVERY_TYPE_MAP[mb_strtolower(trim($value))] ?? null;
    }

    private static function detectDelimiter($handle): string
    {
        $firstLine = fgets($handle);
        rewind($handle);

        if ($firstLine === false) {
            return ',';
        }

        return substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
    }

    /**
     * @return array{0: array<int, string>, 1: int}
     */
    private static function findHeaderAndBuildMap($handle, string $delimiter): array
    {
        $headerRowNum = 0;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $headerRowNum++;

            $headers = array_map([self::class, 'normalizeHeader'], $row);

            if (!self::isHeaderRow($headers)) {
                continue;
            }

            $colMap = [];
            foreach ($headers as $idx => $header) {
                if (isset(self::ALIAS_MAP[$header])) {
                    $colMap[$idx] = self::ALIAS_MAP[$header];
                }
            }

            return [$colMap, $headerRowNum];
        }

        return [[], $headerRowNum];
    }

    /**
     * @param  string[] $headers
     */
    private static function isHeaderRow(array $headers): bool
    {
        $hasFullName = in_array('фио', $headers, true);
        $hasGoods    = in_array('товар', $headers, true) || in_array('товары', $headers, true);

        return $hasFullName && $hasGoods;
    }

    public static function normalizeHeader(string $header): string
    {
        $normalized = mb_strtolower($header);
        $normalized = preg_replace('/[\r\n]+/', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }
}
