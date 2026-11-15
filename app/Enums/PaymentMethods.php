<?php

namespace App\Enums;

enum PaymentMethods: string
{
    case ONLINE = 'آنلاین';
    case CASH = 'پرداخت در محل';
    case PARTIAL = 'پرداخت بیعانه';
    case CARD = 'کارت به کارت';
    case CHEQUE = 'چک';
    case OTHER = 'سایر';

    public static function fromName(string $name): self
    {
        foreach (static::cases() as $case) {
            if ($case->name === strtoupper($name)) {
                return $case;
            }
        }
        throw new \InvalidArgumentException("Invalid value: {$name}");
    }

    public static function names(): array
    {
        return array_column(static::cases(), 'name');
    }

    public static function values(): array
    {
        $values = [];
        foreach (static::cases() as $case) {
            // $values[$case->name] = $case->value;
            $values[] = [
                'id' => $case->name,
                'name' => $case->value
            ];
        }
        return $values;
    }
}
