<?php

namespace Tests\Unit\Enums;

use App\Enums\PriorityStatus;
use PHPUnit\Framework\TestCase;

class PriorityStatusTest extends TestCase
{
    public function test_value_int_mapping(): void
    {
        $this->assertSame(1,  PriorityStatus::Low->valueInt());
        $this->assertSame(5,  PriorityStatus::Medium->valueInt());
        $this->assertSame(10, PriorityStatus::High->valueInt());
    }

    public function test_from_int_buckets_to_correct_priority(): void
    {
        $this->assertSame(PriorityStatus::Low,    PriorityStatus::fromInt(0));
        $this->assertSame(PriorityStatus::Low,    PriorityStatus::fromInt(4));
        $this->assertSame(PriorityStatus::Medium, PriorityStatus::fromInt(5));
        $this->assertSame(PriorityStatus::Medium, PriorityStatus::fromInt(9));
        $this->assertSame(PriorityStatus::High,   PriorityStatus::fromInt(10));
        $this->assertSame(PriorityStatus::High,   PriorityStatus::fromInt(99));
    }

    public function test_round_trip_value_int_then_from_int(): void
    {
        foreach (PriorityStatus::cases() as $case) {
            $this->assertSame($case, PriorityStatus::fromInt($case->valueInt()));
        }
    }
}
