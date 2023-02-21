<?php

namespace App\Enums;

enum OrderStatus: string
{

    case PENDING = 'درانتظار بررسی';
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

    public static function names(): array
    {
        return array_column(static::cases(), 'name');
    }
}
