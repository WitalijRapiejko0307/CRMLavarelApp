<?php

namespace Tests\Unit;

use App\Models\Order;
use PHPUnit\Framework\TestCase;

class OrderStatusesTest extends TestCase
{
    private const CALL_STATUSES = [
        'Недозвон',
        'Недозвон1',
        'Недозвон2',
        'Сомнения',
        'Отдал заявку',
    ];

    public function test_call_statuses_are_in_whitelist(): void
    {
        foreach (self::CALL_STATUSES as $status) {
            $this->assertContains($status, Order::STATUSES, "Missing status: {$status}");
        }
    }

    public function test_call_statuses_follow_perzvonit_before_zakazat(): void
    {
        $statuses = Order::STATUSES;

        $perzvonitIndex = array_search('Перезвонить', $statuses, true);
        $zakazatIndex   = array_search('Заказать', $statuses, true);

        $this->assertNotFalse($perzvonitIndex);
        $this->assertNotFalse($zakazatIndex);

        foreach (self::CALL_STATUSES as $status) {
            $index = array_search($status, $statuses, true);
            $this->assertNotFalse($index, "Missing status: {$status}");
            $this->assertGreaterThan($perzvonitIndex, $index, "{$status} should be after Перезвонить");
            $this->assertLessThan($zakazatIndex, $index, "{$status} should be before Заказать");
        }
    }
}
