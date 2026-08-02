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

    public function test_new_statuses_are_in_whitelist_and_ordered(): void
    {
        $statuses = Order::STATUSES;

        $zakazatIndex = array_search('Заказать', $statuses, true);
        $confirmedIndex = array_search('Подтвержден', $statuses, true);
        $sendIndex = array_search('Отправить', $statuses, true);

        $this->assertNotFalse($zakazatIndex);
        $this->assertNotFalse($confirmedIndex);
        $this->assertNotFalse($sendIndex);
        $this->assertContains('Спам', $statuses);
        $this->assertGreaterThan($zakazatIndex, $confirmedIndex);
        $this->assertGreaterThan($confirmedIndex, $sendIndex);
    }

    public function test_work_statuses_include_confirmed_and_spam(): void
    {
        $this->assertContains('Подтвержден', Order::WORK_STATUSES);
        $this->assertContains('Спам', Order::WORK_STATUSES);
        $this->assertContains('Заказать', Order::WORK_STATUSES);
    }

    public function test_call_center_statuses_include_confirmed_and_spam_but_not_send(): void
    {
        $this->assertContains('Подтвержден', Order::CALL_CENTER_STATUSES);
        $this->assertContains('Спам', Order::CALL_CENTER_STATUSES);
        $this->assertNotContains('Отправить', Order::CALL_CENTER_STATUSES);
    }

    public function test_confirmed_is_non_deletable(): void
    {
        $this->assertContains('Подтвержден', Order::NON_DELETABLE_STATUSES);
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
