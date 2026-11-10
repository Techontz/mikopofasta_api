<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * An in-app notification about something that happened to a customer (header bell). Stored in the database only.
 */
class CustomerActivity extends Notification
{
    /**
     * @param  array{kind: string, title: string, message: string, link: string, customer_id: int}  $payload
     */
    public function __construct(public array $payload) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{kind: string, title: string, message: string, link: string, customer_id: int}
     */
    public function toArray(object $notifiable): array
    {
        return $this->payload;
    }
}
