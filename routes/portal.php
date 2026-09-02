<?php

declare(strict_types=1);

use App\Http\Controllers\Portal\DashboardController;
use App\Http\Controllers\Portal\MediaController;
use App\Http\Controllers\Portal\PostController;
use App\Http\Controllers\Portal\SessionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Client portal
|--------------------------------------------------------------------------
|
| A separate file, not a group in web.php, because the separation is the
| security control: the `customer` guard, this route file, these controllers
| and the portal layout namespace share nothing with the agency surface. A
| portal user cannot reach an agency screen because auth('web')->user() simply
| cannot return one -- not because a check somewhere remembered to say no.
|
| See docs/04-AUTH-RBAC.md sections 1 and 8.
|
*/

Route::prefix('portal')->name('portal.')->group(function (): void {

    /*
     | Sign-in is outside the auth group, and `guest:customer` rather than
     | plain `guest`: an agency user signed in on the `web` guard must still be
     | able to reach the client login, because the same person often has both.
     */
    Route::middleware('guest:customer')->group(function (): void {
        Route::get('login', [SessionController::class, 'create'])->name('login');
        Route::post('login', [SessionController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('login.store');
    });

    Route::post('logout', [SessionController::class, 'destroy'])
        ->middleware('auth:customer')
        ->name('logout');

    Route::middleware('portal')->group(function (): void {

        Route::get('/', DashboardController::class)->name('dashboard');

        Route::get('content', [PostController::class, 'index'])->name('posts.index');
        Route::get('content/{post}', [PostController::class, 'show'])->name('posts.show');

        Route::post('content/{post}/approve', [PostController::class, 'approve'])->name('posts.approve');
        Route::post('content/{post}/reject', [PostController::class, 'reject'])->name('posts.reject');
        Route::post('content/{post}/changes', [PostController::class, 'requestChanges'])->name('posts.changes');

        Route::post('content/{post}/comments', [PostController::class, 'comment'])->name('posts.comment');

        /*
         | Media is streamed, never linked by path: the files sit on a private
         | disk. `signed` is layered on top of `auth:customer` deliberately --
         | the signature stops ids being walked, and the session stops a signed
         | URL being useful to anyone the client forwards it to.
         |
         | Note this cannot use Media::temporaryUrl(): that delegates to the
         | filesystem driver, and the `local` driver -- the default, and what
         | shared hosting will run -- cannot sign URLs at all.
         */
        Route::get('media/{media}', MediaController::class)
            ->middleware('signed')
            ->name('media.show');
    });
});
