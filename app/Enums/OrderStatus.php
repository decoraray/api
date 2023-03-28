<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PENDING = 'درانتظار بررسی';
    case PAID = 'پرداخت شده';
    case CONFIRMED = 'تایید شده';
    case CUTTING = 'برش';
    case CNC = 'سی ان سی';
    case VACUUM = 'وکیوم';
    case COLORING = 'رنگکاری';
    case PVC = 'نوار pvc';
    case ASSEMBLY = 'مونتاژ';
    case PACKAGING = 'بسته بندی';
    case SHIPPING = 'ارسال کالا';
    case DELIVERED = 'تحویل کالا';
    case RETURNED = 'مرجوعی کالا';
    case CANCELED = 'لغو شده';

    public static function fromName(string $name): self
    {
        foreach (static::cases() as $case) {
            if ($case->name === $name) {
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


    public function color(): string
    {
        switch ($this) {
            case self::PENDING:
                return '#Deaa3b';
            case self::PAID:
                return '#7ab917';
            case self::CONFIRMED:
                return '#7ab917';
            case self::CUTTING:
                return '#7ab917';
            case self::CNC:
                return '#7ab917';
            case self::VACUUM:
                return '#7ab917';
            case self::COLORING:
                return '#7ab917';
            case self::PVC:
                return '#7ab917';
            case self::ASSEMBLY:
                return '#7ab917';
            case self::PACKAGING:
                return '#7ab917';
            case self::SHIPPING:
                return '#7ab917';
            case self::DELIVERED:
                return '#7ab917';
            case self::RETURNED:
                return '#C54426';
            case self::CANCELED:
                return '#737373';
            default:
                return '#Deaa3b';
        }
    }
}

// sabz: #7ab917
// qirmizi: #C54426
// narenji: #Deaa3b
// tusi: #737373