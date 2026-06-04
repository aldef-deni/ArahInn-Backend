<?php

namespace App\Providers;

use Illuminate\Support\Facades\Mail;
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
        // Set global Reply-To untuk semua outgoing email.
        // From: noreply@arahinn.com (sender identity)
        // Reply-To: help@arahinn.com (kalau user klik Reply, masuk ke CS)
        $replyTo = config('mail.reply_to');
        if (!empty($replyTo['address'])) {
            Mail::alwaysReplyTo($replyTo['address'], $replyTo['name'] ?? '');
        }
    }
}
