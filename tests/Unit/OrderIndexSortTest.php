<?php

namespace Tests\Unit;

use App\Support\OrderIndexSort;
use PHPUnit\Framework\TestCase;

class OrderIndexSortTest extends TestCase
{
    public function test_resolve_defaults_without_params(): void
    {
        $this->assertSame(['created_at', 'desc'], OrderIndexSort::resolve(null, null));
    }

    public function test_resolve_rejects_unknown_column(): void
    {
        $this->assertSame(['created_at', 'desc'], OrderIndexSort::resolve('goods', 'asc'));
    }

    public function test_resolve_keeps_valid_text_sort(): void
    {
        $this->assertSame(['full_name', 'asc'], OrderIndexSort::resolve('full_name', 'asc'));
        $this->assertSame(['full_name', 'desc'], OrderIndexSort::resolve('full_name', 'DESC'));
    }

    public function test_resolve_fills_dir_for_valid_column(): void
    {
        $this->assertSame(['full_name', 'asc'], OrderIndexSort::resolve('full_name', null));
        $this->assertSame(['created_at', 'desc'], OrderIndexSort::resolve('created_at', 'sideways'));
        $this->assertSame(['id', 'desc'], OrderIndexSort::resolve('id', ''));
    }
}
