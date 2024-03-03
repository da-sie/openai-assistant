<?php

namespace DaSie\Openaiassistant\Enums;

enum CheckmarkStatus: int
{
    case failed = 3;
    case success = 2;
    case processing = 1;
    case none = 0;

    public function toCssClass(): string
    {
        return match ($this) {
            self::success => 'text-green-500',
            self::processing => 'text-yellow-500',
            self::none => 'text-gray-500',
            self::failed => 'text-red-500',
        };
    }

    public function toIcon(): string
    {
        return match ($this) {
            self::success => 'check-circle',
            self::processing => 'clock',
            self::none => 'minus-circle',
            self::failed => 'x-circle',
        };
    }

    public function toText(): string
    {
        return match ($this) {
            self::success => 'Success',
            self::processing => 'Processing',
            self::none => 'None',
            self::failed => 'Failed',
        };
    }

    public function value(): int
    {
        return match ($this) {
            self::success => 2,
            self::processing => 1,
            self::none => 0,
            self::failed => 3,
        };
    }
}
