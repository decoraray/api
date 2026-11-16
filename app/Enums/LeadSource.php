<?php

namespace App\Enums;

enum LeadSource: string
{
    case INSTAGRAM = 'instagram';
    case DIVAR = 'divar';
    case WEBSITE = 'website';
    case PHONE = 'phone';
    case MESSENGER = 'messenger';
    case WALK_IN = 'walk_in';
    case REFERRAL = 'referral';
    case PARTNER = 'partner';
    case GOOGLE = 'google';
    case TOROB = 'torob';

    public function label(): string
    {
        return match ($this) {
            self::INSTAGRAM => 'اینستاگرام',
            self::DIVAR => 'دیوار',
            self::WEBSITE => 'وب‌سایت',
            self::PHONE => 'تماس تلفنی',
            self::MESSENGER => 'واتساپ/تلگرام',
            self::WALK_IN => 'مراجعه حضوری',
            self::REFERRAL => 'معرفی',
            self::PARTNER => 'همکار',
            self::GOOGLE => 'گوگل',
            self::TOROB => 'ترب',
        };
    }

    public static function options(): array
    {
        return array_map(fn (self $c) => ['id' => $c->value, 'label' => $c->label()], self::cases());
    }
}
