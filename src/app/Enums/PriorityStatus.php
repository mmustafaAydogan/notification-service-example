<?php

namespace App\Enums;

enum PriorityStatus: string
{
    case Low    = "Low";
    case Medium = "Medium";
    case High   = "High";

    public function valueInt(): int
    {
        return match ($this) {
            self::Low    => 1,
            self::Medium => 5,
            self::High   => 10,
        };
    }

    public static function fromInt(int $value): self
    {
        return match ($value) {
            1  => self::Low,
            5  => self::Medium,
            10 => self::High,
        };
    }
}
