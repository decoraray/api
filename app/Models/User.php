<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Laravel\Sanctum\HasApiTokens;
use App\Events\OneTimePassGenerated;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'surname',
        'email',
        'phone',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];


    public function isAdmin()
    {
        return $this->role == 'admin';
    }

    public function isUser()
    {
        return $this->role == 'user';
    }

    public function createToken($name)
    {
        return $this->createToken($name)->plainTextToken;
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function cart()
    {
        return $this->hasOne(Cart::class);
    }

    public function addresses()
    {
        return $this->hasMany(Address::class);
    }

    public function getCartAttribute()
    {
        return $this->cart()->first();
    }

    public function generateOneTimePassword()
    {
        // config('auth.otp.expire');
        $this->one_time_password = rand(100000, 999999);
        $this->one_time_password_created_at = now();
        $this->save();

        event(new OneTimePassGenerated($this));

        return true;
    }

    protected function clearOneTimePassword()
    {
        $this->one_time_password = null;
        $this->one_time_password_created_at = null;
        $this->save();
    }

    public function verifyOneTimePassword($password): bool
    {

        if ($this->one_time_password == null || $this->one_time_password_created_at == null) {
            return false;
        }

        // check if time is expired 
        $expired = now()->subMinutes(config('auth.otp.expire')) > $this->one_time_password_created_at;
        if ($expired) {
            $this->clearOneTimePassword();
            return false;
        }

        // check if password is correct
        if ($password ==  $this->one_time_password) {

            $this->phone_verified_at = now();
            $this->clearOneTimePassword(); // this method save the model

            return true;
        }

        return false;
    }
}
