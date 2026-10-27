<?php

use App\Http\Controllers\Api\Admin;
use App\Http\Controllers\Api\Admin\AuditEventController;
use App\Http\Controllers\Api\Admin\OperationsController;
use App\Http\Controllers\Api\Admin\OverviewController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\LibraryController;
use App\Http\Controllers\Api\MfaController;
use App\Http\Controllers\Api\PlaybackController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Controllers\Api\StreamController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController;

Route::prefix('api/v1')->group(function (): void {
    Route::get('session', [SessionController::class, 'show'])->name('api.session');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])->middleware(['guest', 'throttle:login'])->name('api.login');
    Route::post('login/challenge', [TwoFactorAuthenticatedSessionController::class, 'store'])->middleware(['guest', 'throttle:two-factor', 'serialized-mfa'])->name('api.login.challenge');
    Route::middleware(['auth', 'active'])->group(function (): void {
        Route::post('logout', [SessionController::class, 'destroy']);
        Route::patch('me', [SessionController::class, 'update']);
        Route::put('me/password', [SessionController::class, 'password'])->middleware('recent-password');
        Route::post('me/password/confirm', [MfaController::class, 'confirmPassword'])->middleware('throttle:confirm-password');
        Route::get('me/mfa', [MfaController::class, 'show']);
        Route::middleware(['recent-password', 'throttle:confirm-password'])->group(function (): void {
            Route::post('me/mfa', [MfaController::class, 'store']);
            Route::post('me/mfa/confirm', [MfaController::class, 'confirm']);
            Route::get('me/mfa/recovery-codes', [MfaController::class, 'recoveryCodes'])->middleware('mfa');
            Route::post('me/mfa/recovery-codes', [MfaController::class, 'regenerate'])->middleware('mfa');
            Route::delete('me/mfa', [MfaController::class, 'destroy'])->middleware('mfa');
        });
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
            Route::prefix('admin')->middleware(['can:admin', 'mfa'])->group(function (): void {
                Route::middleware('can:catalog.manage')->group(function (): void {
                    Route::get('artists', [Admin\CatalogManagementController::class, 'artists']);
                    Route::get('releases', [Admin\CatalogManagementController::class, 'releases']);
                    Route::get('tracks', [Admin\CatalogManagementController::class, 'tracks']);
                    Route::middleware('recent-password')->group(function (): void {
                        Route::post('artists', [Admin\CatalogManagementController::class, 'saveArtist']);
                        Route::patch('artists/{artist}', [Admin\CatalogManagementController::class, 'saveArtist']);
                        Route::post('releases', [Admin\CatalogManagementController::class, 'saveRelease']);
                        Route::patch('releases/{release}', [Admin\CatalogManagementController::class, 'saveRelease']);
                        Route::put('releases/{release}/tracks', [Admin\CatalogManagementController::class, 'releaseTracks']);
                        Route::patch('tracks/{track}', [Admin\CatalogManagementController::class, 'updateTrack']);
                        Route::post('tracks/{track}/renditions', [Admin\CatalogManagementController::class, 'attachRendition'])->middleware('can:media.manage');
                    });
                });
                Route::get('overview', OverviewController::class)->middleware('can:dashboard.read');
                Route::get('operations', OperationsController::class)->middleware('can:operations.read');
                Route::get('audit-events', [AuditEventController::class, 'index'])->middleware('can:audit.read');
                Route::get('users', [Admin\UserController::class, 'index'])->middleware('can:users.manage');
                Route::post('users', [Admin\UserController::class, 'store'])->middleware(['can:users.manage', 'recent-password']);
                Route::patch('users/{user}', [Admin\UserController::class, 'update'])->middleware(['can:users.manage', 'recent-password']);
                Route::get('media', [Admin\MediaController::class, 'index'])->middleware('can:media.manage');
                Route::post('media', [Admin\MediaController::class, 'store'])->middleware(['can:media.manage', 'recent-password', 'throttle:upload']);
                Route::get('imports', [Admin\ImportController::class, 'index'])->middleware('can:imports.manage');
                Route::post('imports', [Admin\ImportController::class, 'store'])->middleware(['can:imports.manage', 'recent-password']);
                Route::get('imports/{import}', [Admin\ImportController::class, 'show'])->middleware('can:imports.manage');
                Route::post('imports/{import}/commit', [Admin\ImportController::class, 'commit'])->middleware(['can:imports.manage', 'recent-password']);
                Route::post('playlists', [Admin\PlaylistController::class, 'store'])->middleware(['can:catalog.manage', 'recent-password']);
                Route::put('playlists/{playlist}/tracks', [Admin\PlaylistController::class, 'tracks'])->middleware(['can:catalog.manage', 'recent-password']);
                Route::patch('playlists/{playlist}', [Admin\PlaylistController::class, 'update'])->middleware(['can:catalog.manage', 'recent-password']);
                Route::post('playlists/{playlist}/cover', [Admin\PlaylistController::class, 'cover'])->middleware(['can:catalog.manage', 'recent-password']);
            });
        });
    });
});

Route::view('login', 'app')->name('login');
Route::view('/{path?}', 'app')->where('path', '^(?!api(?:/|$)).*')->name('app');
