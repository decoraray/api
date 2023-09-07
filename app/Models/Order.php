<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Order extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'uuid',
        'user_id',
        'address',
        'city',
        'county',
        'postal_code',
        'phone',
        'status',
        'payment_method',
        'total',
        'discount_amount',
        'discount_id',
        'address_id',
        'shipping_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function products()
    {
        return $this->belongsToMany(Product::class)
            ->withPivot('quantity', 'description')
            ->withTimestamps();
    }

    public function discount()
    {
        return $this->hasOne(Discount::class);
    }

    public function items()
    {
        return $this->hasMany(Item::class);
    }

    public function address()
    {
        return $this->hasOne(Address::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function shipping()
    {
        return $this->belongsTo(Shipping::class);
    }

    public function getPayableAmount()
    {
        $method = $this->payment_method;
        switch ($method) {
            case 'online':
                return round($this->total);
            case 'partial':
                return round($this->total * 0.3);
            case 'door':
                return $this->total;
            default:
                return $this->total;
        }
    }

    public static function createFromRequest($request)
    {

        $addressId = $request->address_id;

        $paymentMethod = $request->payment_method;

        $user = auth()->user();

        $address = Address::where('uuid', $addressId)->first();

        $cart = $user->cart;

        $shipping = City::where('id', $address->city_id)->first()->shipping;

        // create order
        $order = Order::make([
            'user_id' => $user->id,
            'uuid' => uniqid(),
            'address' => $address->address,
            'city' => $address->city,
            'county' => $address->county,
            'postal_code' => $address->postal_code,
            'phone' => $address->phone,
            'status' => OrderStatus::PENDING,
            'total' => 0,
            'payment_method' => $paymentMethod,
            'discount_amount' => $cart->discountAmount,
            'discount_id' => $cart->discount_id,
            'address_id' => $address->id,
            'shipping_id' => $shipping->id,
        ]);

        $order->save();

        // add items to order
        $shippingPrice = $shipping->price;
        for ($i = 0; $i < $cart->variations->count(); $i++) {

            Item::create([
                'title' => $cart->variations[$i]->product->title,
                'slug' => $cart->variations[$i]->product->slug,
                'order_id' => $order->id, // order must be saved to have id 
                'product_id' => $cart->variations[$i]->product->id,
                'variation_id' => $cart->variations[$i]->id,
                'quantity' => $cart->variations[$i]->pivot->quantity,
                'price' => $cart->variations[$i]->price,
            ]);
            $shippingPrice += $shipping->price * $shipping->additional_percent / 100;
        }

        // calculate payable amount
        $order->total = $cart->total + $shippingPrice;

        $order->update();

        return $order;
    }
}
