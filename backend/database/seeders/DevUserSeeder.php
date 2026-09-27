<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Development-only admin user for local API testing (Phase 18 auth).
 *
 * This seeder is intentionally NOT registered in DatabaseSeeder::run(), so it
 * never runs during a normal `php artisan db:seed`. Run it explicitly:
 *
 *     php artisan db:seed --class=DevUserSeeder
 *
 * Credentials come from the environment so no password is ever hardcoded:
 *
 *     DEV_USER_EMAIL=dev@pos.test
 *     DEV_USER_PASSWORD=password
 *
 * The seeder refuses to run in production and never overwrites an existing
 * user's password.
 */
class DevUserSeeder extends Seeder
{
    /**
     * Run the development user seeder.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('DevUserSeeder must not run in production. Aborting.');

            return;
        }

        $email = (string) env('DEV_USER_EMAIL', 'dev@pos.test');
        $password = (string) env('DEV_USER_PASSWORD', 'password');

        $business = Business::firstOrCreate(
            ['name' => 'Demo Business'],
            ['currency' => 'USD', 'timezone' => 'UTC'],
        );

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'business_id' => $business->id,
                'name' => 'Dev Admin',
                // Hashed by the model's `password => hashed` cast.
                'password' => $password,
                'status' => User::STATUS_ACTIVE,
            ],
        );

        $this->command?->info("Development user ready: {$user->email} (business #{$user->business_id})");
    }
}
