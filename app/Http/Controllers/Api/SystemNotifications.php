<?php

namespace App\Http\Controllers\Api;

use App\Models\SystemNotification;
use Illuminate\Http\Request;

class SystemNotifications
{
    public function latest(): array
    {
        $items = SystemNotification::query()
            ->latest()
            ->limit(3)
            ->get(['id', 'title', 'message', 'level', 'is_read', 'created_at']);

        return [
            'unread_count' => SystemNotification::query()->where('is_read', false)->count(),
            'items' => $items->toArray(),
        ];
    }

    public function markAllRead(): array
    {
        $updated = SystemNotification::query()
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return [
            'success' => true,
            'updated' => $updated,
        ];
    }

    public function markRead(Request $request): array
    {
        $validated = $request->validate([
            'id' => 'required|integer|exists:system_notifications,id',
        ]);

        $notification = SystemNotification::query()->findOrFail((int) $validated['id']);
        $notification->is_read = true;
        $notification->save();

        return [
            'success' => true,
            'id' => $notification->id,
        ];
    }
}
