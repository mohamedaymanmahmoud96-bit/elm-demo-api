<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$teacher = App\Models\User::where('email', 'teacher@elm.com')->first();
if (!$teacher) {
    echo "missing\n";
    exit(1);
}

App\Models\User::where('id', $teacher->id)->update([
    'password' => Illuminate\Support\Facades\Hash::make('password123'),
]);

echo Illuminate\Support\Facades\Hash::check('password123', $teacher->fresh()->password) ? "ok\n" : "bad\n";
