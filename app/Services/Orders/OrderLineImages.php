<?php

namespace App\Services\Orders;

use App\Models\Item;
use App\Models\OrderItemImage;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Attaches uploaded reference images to new order lines. Call inside the order's transaction. */
class OrderLineImages
{
    /**
     * Every named image must exist, be unattached, be the staff member's own upload and be named once.
     * The rows stay locked until the transaction ends, so two orders cannot claim the same upload.
     */
    public function assertAvailable(array $lines, ?User $staff): void
    {
        $all = array_merge([], ...array_column($lines, 'image_ids'));
        if (!$all) {
            return;
        }

        $available = OrderItemImage::whereIn('id', $all)
            ->whereNull('item_id')
            ->where('uploaded_by', $staff?->id)
            ->lockForUpdate()
            ->pluck('id')
            ->flip()
            ->all();

        $errors = [];
        foreach ($lines as $index => $line) {
            foreach ($line['image_ids'] as $id) {
                if (!isset($available[$id])) {
                    $errors["lines.$index.image_ids"] = ['تصویر انتخاب‌شده معتبر نیست یا قبلاً استفاده شده است. دوباره بارگذاری کنید.'];
                }
                unset($available[$id]); // naming it again (on this or a later line) fails
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param int[] $ids in display order */
    public function attach(Item $item, array $ids): void
    {
        foreach ($ids as $position => $id) {
            OrderItemImage::whereKey($id)->update(['item_id' => $item->id, 'position' => $position]);
        }
    }
}
