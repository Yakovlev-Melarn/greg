<?php

namespace App\Providers;

use App\Models\SystemNotification;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer('layouts.app', static function ($view) {
            $latestNotifications = SystemNotification::query()
                ->latest()
                ->limit(3)
                ->get();

            $unreadCount = SystemNotification::query()
                ->where('is_read', false)
                ->count();

            $view->with('latestSystemNotifications', $latestNotifications);
            $view->with('unreadSystemNotificationsCount', $unreadCount);
        });
    }
}
