<?php

require __DIR__.'/backend/vendor/autoload.php';

$app = require_once __DIR__.'/backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = App\Models\User::where('email', 'admin@test.com')->first();

if ($user === null) {
    file_put_contents(__DIR__.'/x.txt', 'admin user not found');
    exit(1);
}

// Assignment goes through the model's `password => hashed` cast, so the stored
// value is a proper bcrypt hash rather than the plain string.
$user->password = 'password';
$user->status = App\Models\User::STATUS_ACTIVE;
$user->save();

file_put_contents(__DIR__.'/x.txt', implode(' | ', [
    'password hash prefix: '.substr($user->fresh()->password, 0, 7),
    'status: '.$user->fresh()->status,
    'roles: '.$user->fresh()->roles->pluck('slug')->implode(','),
]));
