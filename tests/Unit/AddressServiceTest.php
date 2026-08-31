<?php

namespace Tests\Unit;

use App\Services\AddressService;
use PHPUnit\Framework\TestCase;

class AddressServiceTest extends TestCase
{
    public function test_extract_postcode_from_city(): void
    {
        $service = new AddressService();

        $this->assertSame('211840', $service->extractPostcodeFromCity('211840 г. Поставы'));
        $this->assertSame('220012', $service->extractPostcodeFromCity('г. Минск 220012'));
        $this->assertNull($service->extractPostcodeFromCity('Любань'));
        $this->assertNull($service->extractPostcodeFromCity('12345 слишком коротко'));
    }

    public function test_auto_resolve_uses_postcode_when_index_in_city(): void
    {
        $service = new RecordingAddressService([
            '211840' => [
                [
                    'id'       => 'ops-1',
                    'city'     => 'Поставы',
                    'street'   => 'Ленина',
                    'postcode' => '211840',
                    'params'   => null,
                ],
            ],
        ]);

        $item = $service->autoResolve('211840 г. Поставы', 'Ленина', '10');

        $this->assertNotNull($item);
        $this->assertSame('ops-1', $item['id']);
        $this->assertSame(['211840'], $service->searches);
    }

    public function test_auto_resolve_falls_back_to_city_street_without_index(): void
    {
        $legacyQuery = 'минск ленина';
        $service = new RecordingAddressService([
            $legacyQuery => [
                [
                    'id'       => 'ops-2',
                    'city'     => 'Минск',
                    'street'   => 'Ленина',
                    'postcode' => '220000',
                    'params'   => null,
                ],
            ],
        ]);

        $item = $service->autoResolve('Минск', 'Ленина', '5');

        $this->assertNotNull($item);
        $this->assertSame('ops-2', $item['id']);
        $this->assertSame([$legacyQuery], $service->searches);
    }

    public function test_auto_resolve_does_not_legacy_fallback_when_street_matched_but_house_missing(): void
    {
        $service = new RecordingAddressService([
            '211840' => [
                [
                    'id'       => 'ops-3',
                    'city'     => 'Поставы',
                    'street'   => 'Ленина',
                    'postcode' => '211840',
                    'params'   => [
                        ['house' => '1'],
                        ['house' => '2'],
                    ],
                ],
            ],
        ]);

        $item = $service->autoResolve('211840 г. Поставы', 'Ленина', '192');

        $this->assertNull($item);
        $this->assertSame(['211840'], $service->searches);
    }
}

class RecordingAddressService extends AddressService
{
    /** @var string[] */
    public array $searches = [];

    /** @param array<string, array> $responses */
    public function __construct(private array $responses)
    {
    }

    public function search(string $query): array
    {
        $normalized = $this->normalizeAddressPart($query);
        $this->searches[] = $normalized;

        return $this->responses[$normalized] ?? [];
    }
}
