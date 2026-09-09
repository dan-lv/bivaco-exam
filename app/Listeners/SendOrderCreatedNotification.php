<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Notifications\OrderCreatedNotification;
use Throwable;

class SendOrderCreatedNotification
{
    public function handle(OrderCreated $event): void
    {
        try {
            $event->order->user->notify(
                new OrderCreatedNotification($event->order)
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
