<?php

namespace Tests\Unit;

use App\Services\BelpostTrackingService;
use PHPUnit\Framework\TestCase;

class BelpostTrackingParserTest extends TestCase
{
    public function test_code_25_in_history_sets_return_in_transit(): void
    {
        $item = [
            'steps' => [
                ['event' => 'Поступило в учреждение доставки', 'created_at' => '2026-08-01 10:00', 'code' => 3],
                ['event' => 'Подготовлено для возврата', 'created_at' => '2026-07-30 09:00', 'code' => 25],
            ],
        ];

        $result = BelpostTrackingService::parseBelpostTrackingItem($item);

        $this->assertSame('Поступило в учреждение доставки', $result['event']);
        $this->assertSame('Возврат в пути', $result['targetStatus']);
    }

    public function test_arrived_without_return_marker_has_no_target_status(): void
    {
        $item = [
            'steps' => [
                ['event' => 'Поступило в учреждение доставки', 'created_at' => '2026-08-01 10:00', 'code' => 3],
                ['event' => 'Отправлено', 'created_at' => '2026-07-28 09:00', 'code' => 1],
            ],
        ];

        $result = BelpostTrackingService::parseBelpostTrackingItem($item);

        $this->assertSame('Поступило в учреждение доставки', $result['event']);
        $this->assertNull($result['targetStatus']);
    }

    public function test_handed_to_sender_sets_return(): void
    {
        $item = [
            'steps' => [
                ['event' => 'Вручено отправителю', 'created_at' => '2026-08-02 12:00', 'code' => 26],
                ['event' => 'Подготовлено для возврата', 'created_at' => '2026-07-30 09:00', 'code' => 25],
            ],
        ];

        $result = BelpostTrackingService::parseBelpostTrackingItem($item);

        $this->assertSame('Вручено отправителю', $result['event']);
        $this->assertSame('Возврат', $result['targetStatus']);
    }

    public function test_map_prepared_for_return_sets_return_in_transit(): void
    {
        $result = BelpostTrackingService::parseBelpostTrackingFromMapEntry([
            'event'     => 'Подготовлено для возврата',
            'createdAt' => '2026-07-30 09:00',
        ]);

        $this->assertSame('Возврат в пути', $result['targetStatus']);
    }

    public function test_needs_steps_lookup_for_arrived_at_office(): void
    {
        $this->assertTrue(BelpostTrackingService::needsBelpostStepsLookup(
            ['event' => 'Поступило в учреждение доставки', 'createdAt' => 'now'],
            'Отправлено'
        ));
    }

    public function test_needs_steps_lookup_when_at_office_and_transit_event(): void
    {
        $this->assertTrue(BelpostTrackingService::needsBelpostStepsLookup(
            ['event' => 'Отправлено', 'createdAt' => 'now'],
            'В отделении'
        ));
    }

    public function test_unambiguous_sent_event_does_not_need_steps_lookup(): void
    {
        $this->assertFalse(BelpostTrackingService::needsBelpostStepsLookup(
            ['event' => 'Отправлено', 'createdAt' => 'now'],
            'Оформлен'
        ));
    }
}
