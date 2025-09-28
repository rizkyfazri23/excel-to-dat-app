<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ExcelUploadController;

Route::get('/login', function () {
    return view('auth/login'); 
})->name('login');

Route::post('/login', [AuthController::class, 'login'])->name('login.post');

Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware(['auth'])->group(function () {
    
    Route::get('/', [HomeController::class, 'index']);
    Route::post('/generate', [ExcelUploadController::class, 'generate'])->name('generate');
});

