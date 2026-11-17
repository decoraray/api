<?php

namespace App\Enums;

/** The workshop stages a work order moves through, in their usual order. Stored by name. */
enum ProductionStage: string
{
    case CUTTING = 'CUTTING';
    case CNC = 'CNC';
    case VACUUM = 'VACUUM';
    case COLORING = 'COLORING';
    case PVC = 'PVC';
    case ASSEMBLY = 'ASSEMBLY';
    case QC = 'QC';
    case PACKAGING = 'PACKAGING';

    public function label(): string
    {
        return $this === self::QC ? 'کنترل کیفیت' : $this->toOrderStatus()->value;
    }

    /** The order status that stands for this stage. Orders have no QC status, so QC shows as assembly. */
    public function toOrderStatus(): OrderStatus
    {
        return $this === self::QC ? OrderStatus::ASSEMBLY : OrderStatus::fromName($this->name);
    }

    /** Order statuses that count as "in production", least advanced first. */
    public static function orderStatuses(): array
    {
        return [
            OrderStatus::CUTTING,
            OrderStatus::CNC,
            OrderStatus::VACUUM,
            OrderStatus::COLORING,
            OrderStatus::PVC,
            OrderStatus::ASSEMBLY,
            OrderStatus::PACKAGING,
        ];
    }

    /** True when an order in this status is being, or is about to be, produced. */
    public static function orderIsProducible(OrderStatus|string|null $status): bool
    {
        $status = $status instanceof OrderStatus ? $status : OrderStatus::tryFrom((string) $status);

        return $status === OrderStatus::CONFIRMED || in_array($status, self::orderStatuses(), true);
    }

    public static function names(): array
    {
        return array_column(self::cases(), 'name');
    }

    public static function values(): array
    {
        return array_map(fn (self $stage) => ['id' => $stage->name, 'name' => $stage->label()], self::cases());
    }
}
