<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LoginSessionHeartbeatController;
use App\Http\Controllers\OcrDocumentFileController;


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
