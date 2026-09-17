<?php

namespace Tests\Unit;

use App\Models\MailBatch;
use PHPUnit\Framework\TestCase;

class MailBatchWeightHintTest extends TestCase
{
    public function test_light_at_300g_and_below(): void
    {
        $hint = MailBatch::weightHint(200);
        $this->assertSame('ecommerce_light', $hint['type']);
        $this->assertSame('Лайт', $hint['label']);
        $this->assertSame(0.3, $hint['max_kg']);

        $this->assertSame('ecommerce_light', MailBatch::weightHint(300)['type']);
        $this->assertSame('ecommerce_light', MailBatch::weightHint(0)['type']);
    }

    public function test_optima_between_light_and_standard(): void
    {
        $this->assertSame('ecommerce_optima', MailBatch::weightHint(301)['type']);
        $this->assertSame('Оптима', MailBatch::weightHint(301)['label']);
        $this->assertSame('ecommerce_optima', MailBatch::weightHint(600)['type']);
    }

    public function test_standard_above_600g(): void
    {
        $hint = MailBatch::weightHint(800);
        $this->assertSame('ecommerce_standard', $hint['type']);
        $this->assertSame('Стандарт', $hint['label']);
        $this->assertNull($hint['max_kg']);
    }
}
