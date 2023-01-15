<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Color extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'hex',
    ];

    public function products()
    {
        return $this->belongsToMany(ProductVariation::class);
    }

    public static function findOrCreate($name)
    {
        $color = Color::where('name', $name)->first();

        if ($color) {
            return $color;
        }

        $color = new Color();
        $color->name = $name;
        $color->hex = '#000000';
        $color->save();

        return $color;
    }
}
