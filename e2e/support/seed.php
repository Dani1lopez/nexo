<?php

// Creates the fictitious accounts of an e2e run. Credentials come from the environment (see accounts.ts).

use App\Modules\Access\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

foreach (['TREASURY' => 'treasury', 'BOARD' => 'board'] as $prefix => $role) {
    User::provision(
        'Persona ficticia '.$role,
        (string) getenv("E2E_{$prefix}_EMAIL"),
        (string) getenv("E2E_{$prefix}_PASSWORD"),
        $role,
    );
}
