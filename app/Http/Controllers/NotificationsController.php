<?php

namespace App\Http\Controllers;

use App\Models\SystemNotification;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class NotificationsController
{
    public function index(Request $request): Factory|View
    {
        $query = SystemNotification::query()->latest();

        if ($request->filled('level')) {
            $query->where('level', $request->string('level'));
        }

        if ($request->filled('read_status')) {
            if ($request->string('read_status')->value() === 'read') {
                $query->where('is_read', true);
            }
            if ($request->string('read_status')->value() === 'unread') {
                $query->where('is_read', false);
            }
        }

        if ($request->filled('q')) {
            $q = trim((string) $request->get('q'));
            $query->where(function ($subQuery) use ($q) {
                $subQuery
                    ->where('title', 'like', "%{$q}%")
                    ->orWhere('message', 'like', "%{$q}%");
            });
        }

        return view('Notifications/index', [
            'notifications' => $query->paginate(20)->withQueryString(),
            'selectedLevel' => (string) $request->get('level', ''),
            'selectedReadStatus' => (string) $request->get('read_status', ''),
            'search' => (string) $request->get('q', ''),
        ]);
    }
}
