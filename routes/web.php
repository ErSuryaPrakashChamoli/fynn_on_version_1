<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LoginSessionHeartbeatController;

Route::get('/', function () {
    return view('welcome');
});

Route::redirect('/', '/admin');

Route::middleware('auth')->post(
    '/login-session/heartbeat',
    LoginSessionHeartbeatController::class
)->name('login-session.heartbeat');
