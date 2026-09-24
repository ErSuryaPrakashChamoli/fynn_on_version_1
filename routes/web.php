<?php

use App\Http\Controllers\LoginSessionHeartbeatController;
use App\Http\Controllers\OcrDocumentFileController;
use App\Http\Controllers\SwitchDemoRoleController;
use App\Http\Middleware\Portal\EnsureDemoIsAvailable;
use App\Http\Middleware\Portal\UseDemoContext;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::redirect('/', '/admin');

Route::middleware('auth')->post(
    '/login-session/heartbeat',
    LoginSessionHeartbeatController::class
)->name('login-session.heartbeat');

Route::middleware('auth')->post(
    '/login-session/heartbeat',
    LoginSessionHeartbeatController::class
)->name('login-session.heartbeat');

Route::middleware('auth')->get('/ocr-documents/{ocrDocument}/file', OcrDocumentFileController::class)
    ->name('ocr-documents.file');

/*
 * The /demo copies of the plain routes above. Same controllers, but run in
 * the demo context (demo database + storage) and authenticated on the
 * `demo` guard, so a demo login can reach them and a main login cannot.
 * UseDemoContext is ordered ahead of route-model binding and
 * authentication by the middleware priority list in bootstrap/app.php.
 */
Route::prefix('demo')
    ->name('demo.')
    ->middleware([EnsureDemoIsAvailable::class, UseDemoContext::class, 'auth:demo'])
    ->group(function (): void {
        Route::post('/login-session/heartbeat', LoginSessionHeartbeatController::class)
            ->name('login-session.heartbeat');

        Route::get('/ocr-documents/{ocrDocument}/file', OcrDocumentFileController::class)
            ->name('ocr-documents.file');

        // The "View as" switcher in the /demo topbar: a role's seeded
        // login, or any specific demo user.
        Route::post('/switch-role/{role}', SwitchDemoRoleController::class)
            ->name('switch-role');

        Route::post('/switch-user/{user}', [SwitchDemoRoleController::class, 'user'])
            ->whereNumber('user')
            ->name('switch-user');
    });
