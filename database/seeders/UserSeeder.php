<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('users')->insert([
            'name' => 'admin',
            'surname' => 'admin',
            'email' => 'admin@admin',
            'phone' => '5528999690',
            'password' => '$2y$10$iNxVpcKb092CsY2WcJ2E9upshpRLCwHZ2xivIVkEOGni5wSyYif.G', // admin
        ]);
    }
}
