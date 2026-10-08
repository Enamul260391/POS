<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PlatformOwnerSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('PLATFORM_OWNER_EMAIL', 'owner@example.com');
        $password = env('PLATFORM_OWNER_PASSWORD', 'password123');
        $name = env('PLATFORM_OWNER_NAME', 'Platform Owner');

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'business_id' => null,
                'platform_role' => User::PLATFORM_ROLE_OWNER,
                'status' => User::STATUS_ACTIVE,
                'email_verified_at' => now(),
            ]
        );

        $this->command->info("Platform Owner created: {$email}");
    }
}