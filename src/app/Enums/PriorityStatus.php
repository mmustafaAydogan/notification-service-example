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
        return match (true) {
            $value >= 10 => self::High,
            $value >= 5  => self::Medium,
            default      => self::Low,
        };
    }
}
