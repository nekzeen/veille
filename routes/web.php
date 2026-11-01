<?php

use App\Http\Controllers\CatalogueController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserSourceController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/sources/catalogue', [CatalogueController::class, 'index'])->name('sources.catalogue');
    Route::get('/sources', [UserSourceController::class, 'index'])->name('sources.index');
    Route::post('/sources/{source}/subscribe', [UserSourceController::class, 'store'])->name('sources.subscribe');
    Route::post('/sources/personal', [UserSourceController::class, 'storePersonal'])->name('sources.personal');
    Route::delete('/sources/{userSource}', [UserSourceController::class, 'destroy'])->name('sources.unsubscribe');
});

require __DIR__.'/auth.php';
