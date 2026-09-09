<?php

use App\Actions\CreateOrderAction;
use App\Exceptions\OrderConflictException;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if (count($argv) !== 6) {
    fwrite(STDERR, "Expected user, warehouse, product, quantity, and start time.\n");
    exit(2);
}

[, $userId, $warehouseId, $productId, $quantity, $startAt] = $argv;

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

while (microtime(true) < (float) $startAt) {
    usleep(1000);
}

try {
    $user = User::query()->findOrFail((int) $userId);

    $app->make(CreateOrderAction::class)->execute(
        $user,
        (int) $warehouseId,
        [['product_id' => (int) $productId, 'quantity' => (int) $quantity]],
    );

    echo "created\n";
} catch (OrderConflictException) {
    echo "conflict\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
