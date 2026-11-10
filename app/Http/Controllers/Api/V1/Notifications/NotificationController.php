<?php

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The signed-in employee's own notifications (header bell): the latest ones with the unread count, and marking them read.
 */
class NotificationController extends ApiController
{
    private const SHOWN = 30;

    public function index(): JsonResponse
    {
        $employee = $this->currentEmployee();

        return response()->json(['data' => [
            'unread_count' => $employee->unreadNotifications()->count(),
            'items' => $employee->notifications()->limit(self::SHOWN)->get()->map(fn (DatabaseNotification $notification): array => [
                'id' => $notification->id,
                'kind' => $notification->data['kind'] ?? null,
                'title' => $notification->data['title'] ?? '',
                'message' => $notification->data['message'] ?? '',
                'link' => $notification->data['link'] ?? null,
                'read_at' => $notification->read_at?->toIso8601String(),
                'created_at' => $notification->created_at?->toIso8601String(),
            ])->values(),
        ]]);
    }

    public function read(string $notification): JsonResponse
    {
        $this->currentEmployee()->notifications()->whereKey($notification)->firstOrFail()->markAsRead();

        return $this->message('Notification read');
    }

    public function readAll(): JsonResponse
    {
        $this->currentEmployee()->unreadNotifications()->update(['read_at' => now()]);

        return $this->message('All notifications read');
    }
}
