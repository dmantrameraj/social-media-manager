<?php

declare(strict_types=1);

use App\Http\Controllers\Webhook\RazorpayWebhookController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));

/*
|--------------------------------------------------------------------------
| Webhooks
|--------------------------------------------------------------------------
|
| Excluded from CSRF, because the sender is a server rather than a browser
| session. Protected instead by HMAC signature verification inside the
| controller, plus rate limiting here. The exclusion list is deliberately
| short and reviewed -- see docs/10-SECURITY.md section 7.
|
*/
Route::post('/webhooks/razorpay', RazorpayWebhookController::class)
    ->middleware('throttle:100,1')
    ->withoutMiddleware([
        PreventRequestForgery::class,
    ])
    ->name('webhooks.razorpay');
