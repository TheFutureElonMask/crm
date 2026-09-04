<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $admins = [
            [
                'name' => 'Admin',
                'email' => 'admin@mfpto.com',
                'password' => 'password123',
            ],
            [
                'name' => 'Admin 2',
                'email' => 'admin2@mfpto.com',
                'password' => 'Qwerty123',
            ],
        ];

        foreach ($admins as $admin) {
            User::updateOrCreate(
                ['email' => $admin['email']],
                [
                    'name' => $admin['name'],
                    'password' => Hash::make($admin['password']),
                    'role' => 'admin',
                    'status' => 'offline',
                ]
            );
        }
    }
}
