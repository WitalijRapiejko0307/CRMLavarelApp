<?php

namespace Tests\Unit;

use App\Rules\FullNameTwoParts;
use PHPUnit\Framework\TestCase;

class FullNameTwoPartsTest extends TestCase
{
    private FullNameTwoParts $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rule = new FullNameTwoParts();
    }

    /** @dataProvider validNamesProvider */
    public function test_valid_names_pass(string $name): void
    {
        $this->assertTrue($this->rule->passes('full_name', $name));
    }

    public function validNamesProvider(): array
    {
        return [
            'surname and name'       => ['Иванов Иван'],
            'three parts still ok'   => ['Иванов Иван Иванович'],
            'extra spaces collapsed' => ['  Петров   Пётр  '],
        ];
    }

    /** @dataProvider invalidNamesProvider */
    public function test_invalid_names_fail(string $name): void
    {
        $this->assertFalse($this->rule->passes('full_name', $name));
    }

    public function invalidNamesProvider(): array
    {
        return [
            'single word'       => ['Иванов'],
            'single word trailing space' => ['Иванов '],
            'short part'        => ['И Иванов'],
            'empty'             => [''],
            'only spaces'       => ['   '],
        ];
    }
}
