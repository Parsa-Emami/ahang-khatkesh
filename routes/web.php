<?php

use App\Http\Controllers\Api\Admin;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\LibraryController;
use App\Http\Controllers\Api\PlaybackController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Controllers\Api\StreamController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->group(function (): void {
    Route::get('session', [SessionController::class, 'show'])->name('api.session');
    Route::post('login', [SessionController::class, 'store'])->middleware('throttle:login');
    Route::middleware(['auth', 'active'])->group(function (): void {
        Route::post('logout', [SessionController::class, 'destroy']);
        Route::patch('me', [SessionController::class, 'update']);
        Route::put('me/password', [SessionController::class, 'password']);
        Route::get('tracks/{track}/stream', StreamController::class)->name('api.tracks.stream');
        Route::get('playlists/{playlist}/cover', [CatalogController::class, 'cover'])->name('api.playlists.cover');
        Route::middleware('throttle:catalog')->group(function (): void {
            Route::get('stats', [CatalogController::class, 'stats']);
            Route::get('playlists', [CatalogController::class, 'playlists']);
            Route::get('playlists/{playlist}', [CatalogController::class, 'show']);
            Route::get('tracks', [CatalogController::class, 'tracks']);
            Route::put('tracks/{track}/like', [LibraryController::class, 'like']);
            Route::delete('tracks/{track}/like', [LibraryController::class, 'unlike']);
            Route::put('playlists/{playlist}/save', [LibraryController::class, 'save']);
            Route::delete('playlists/{playlist}/save', [LibraryController::class, 'unsave']);
            Route::get('playback', [PlaybackController::class, 'show']);
            Route::put('playback', [PlaybackController::class, 'update']);
            Route::prefix('admin')->middleware('can:admin')->group(function (): void {
                Route::get('users', [Admin\UserController::class, 'index']);
                Route::post('users', [Admin\UserController::class, 'store']);
                Route::patch('users/{user}', [Admin\UserController::class, 'update']);
                Route::get('media', [Admin\MediaController::class, 'index']);
                Route::post('media', [Admin\MediaController::class, 'store'])->middleware('throttle:upload');
                Route::get('imports', [Admin\ImportController::class, 'index']);
                Route::post('imports', [Admin\ImportController::class, 'store']);
                Route::get('imports/{import}', [Admin\ImportController::class, 'show']);
                Route::post('imports/{import}/commit', [Admin\ImportController::class, 'commit']);
                Route::patch('playlists/{playlist}', [Admin\PlaylistController::class, 'update']);
                Route::post('playlists/{playlist}/cover', [Admin\PlaylistController::class, 'cover']);
            });
        });
    });
});

Route::view('login', 'app')->name('login');
Route::view('/{path?}', 'app')->where('path', '^(?!api(?:/|$)).*')->name('app');
