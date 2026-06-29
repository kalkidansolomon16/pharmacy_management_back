<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

use function Symfony\Component\Clock\now;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::create([
            'email'=>'admin@pharmacy.com',
            'password'=>Hash::make('password123'),
            'status'=>'active',
            'role'=>'Admin',
            'address'=>'Addis Abeba',
            'phone'=>'0988283088',
        ]);
    }
}
