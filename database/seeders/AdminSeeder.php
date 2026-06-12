<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        // Main Admin
        User::create([
            'name' => 'Beast Admin',
            'email' => 'beast@gmail.com',
            'password' => Hash::make('beast123'),
            'phone' => '+255123456789',
            'role' => 'admin',
            'status' => 'active',
        ]);

        // Additional Admin
        User::create([
            'name' => 'Super Admin',
            'email' => 'admin@cashodds.com',
            'password' => Hash::make('admin123'),
            'phone' => '+1234567890',
            'role' => 'admin',
            'status' => 'active',
        ]);

        // Demo tipster
        User::create([
            'name' => 'Pro Tipster',
            'email' => 'tipster@cashodds.com',
            'password' => Hash::make('tipster123'),
            'phone' => '+1234567891',
            'role' => 'tipster',
            'status' => 'active',
        ]);

        // Demo subscriber
        User::create([
            'name' => 'John Subscriber',
            'email' => 'user@cashodds.com',
            'password' => Hash::make('user123'),
            'phone' => '+1234567892',
            'role' => 'subscriber',
            'status' => 'active',
        ]);
    }
}
