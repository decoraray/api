<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Services\Campaign\CampaignService;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Cart extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'uuid',
        'discount_id',
        'user_id'
    ];

    public static function boot()
    {
        parent::boot();
        self::creating(function ($model) {
            $model->uuid = $model->uuid ?? uniqid();
        });
    }

    public function variations()
    {
        return $this->belongsToMany(Variation::class)
            ->withPivot('quantity', 'description')
            ->withTimestamps();
    }

    public function variationsIds()
    {
        return $this->isEmpty ? [] : $this->variations()->pluck('variation_id')->toArray();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // merge another carts variations in this
    public function merge(Cart $firstCart)
    {
        $firstCart->variations()->each(function ($variation) {
            $this->add($variation);
        });

        $firstCart->delete();

        $this->save();
    }

    private function add($variation)
    {
        if ($this->variations->contains($variation)) {
            $this->variations()->updateExistingPivot($variation, [
                'quantity' => $this->variations()->find($variation)->pivot->quantity + $variation->pivot->quantity,
                'description' => $variation->pivot->description ?? '',
            ]);
        } else {
            $this->variations()->attach($variation, [
                'quantity' => $variation->pivot->quantity,
                'description' => $variation->pivot->description ?? '',
            ]);
        }
    }

    public function total()
    {
        $total = 0;
        for ($i = 0; $i < count($this->variationsIds()); $i++) {
            $total += $this->variations()->find($this->variationsIds()[$i])->pivot->quantity
                * $this->variations()->find($this->variationsIds()[$i])->calculatedPrice;
        }
        return $total;
    }

    // total will be calculated with discounted amounts.
    public function totalQuantity()
    {
        $total = 0;
        for ($i = 0; $i < count($this->variationsIds()); $i++) {
            $total += $this->variations()->find($this->variationsIds()[$i])->pivot->quantity;
        }
        return $total;
    }

    public function discount()
    {
        $old_total = 0;
        for ($i = 0; $i < count($this->variationsIds()); $i++) {
            $old_total += $this->variations()->find($this->variationsIds()[$i])->pivot->quantity
                * $this->variations()->find($this->variationsIds()[$i])->oldPrice;
        }
        return $old_total - $this->total();
    }

    public function campaign()
    {
        return CampaignService::calculate($this->total());
    }

    public function clear()
    {
        $this->variations()->detach();
    }

    public function isEmpty()
    {
        return $this->variations()->count() == 0;
    }

    // get total attribute

    public function getTotalAttribute()
    {
        return $this->total();
    }

    public function getTotalQuantityAttribute()
    {
        return $this->totalQuantity();
    }

    public function getDiscountAttribute()
    {
        return $this->discount();
    }

    public function getIsEmptyAttribute()
    {
        return $this->isEmpty();
    }

    public function getDiscountAmountAttribute()
    {
        return $this->discount();
    }

    public function getCampaignAmountAttribute()
    {
        return $this->campaign();
    }

    public static function getOrCreate(?string $uuid = null)
    {
        if (auth()->check()) {
            $cart = Cart::firstOrCreate([
                'user_id' => auth()->id(),
            ]);

            if (!$cart->uuid) {
                $cart->uuid = uniqid();
                $cart->update();
            }

            return $cart;
        } else {
            return Cart::firstOrCreate([
                'uuid' => $uuid ?? uniqid(),
            ]);
        }
    }
}
