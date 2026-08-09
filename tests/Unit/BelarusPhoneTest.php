<?php

namespace Tests\Unit;

use App\Rules\BelarusPhone;
use PHPUnit\Framework\TestCase;

class BelarusPhoneTest extends TestCase
{
    private BelarusPhone $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rule = new BelarusPhone();
    }

    /** @dataProvider validProvider */
    public function test_valid_phones_pass(string $phone): void
    {
        $this->assertTrue($this->rule->passes('phone', $phone));
    }

    public function validProvider(): array
    {
        return [
            '9 digits'        => ['291234567'],
            '375 prefix'      => ['375291234567'],
            'plus prefix'     => ['+375291234567'],
            '80 prefix'       => ['80291234567'],
        ];
    }

    /** @dataProvider invalidProvider */
    public function test_invalid_phones_fail(string $phone): void
    {
        $this->assertFalse($this->rule->passes('phone', $phone));
    }

    public function invalidProvider(): array
    {
        return [
            'empty'       => [''],
            'too short'   => ['12345'],
            'spaces only' => ['   '],
        ];
    }
}
