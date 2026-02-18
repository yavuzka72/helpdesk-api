<?php

namespace App\Helpers;

use App\Events\NotificationCreated;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Model;

class Notifier
{
    public static function send(int $userId, string $type, string $title, string $message, ?Model $model = null): void
    {
        $notification = Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'related_type' => $model ? class_basename($model) : null,
            'related_id' => $model?->getKey(),
            'is_read' => false,
        ]);

        event(new NotificationCreated($notification));
    }
}
