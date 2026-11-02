<?php

use App\Http\Controllers\CatalogueController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserSourceController;
use App\Http\Controllers\VeilleController;
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

    Route::get('/veilles', [VeilleController::class, 'index'])->name('veilles.index');
    Route::get('/veilles/create', [VeilleController::class, 'create'])->name('veilles.create');
    Route::post('/veilles', [VeilleController::class, 'store'])->name('veilles.store');
    Route::get('/veilles/{veille}', [VeilleController::class, 'show'])->name('veilles.show');
    Route::get('/veilles/{veille}/edit', [VeilleController::class, 'edit'])->name('veilles.edit');
    Route::put('/veilles/{veille}', [VeilleController::class, 'update'])->name('veilles.update');
    Route::patch('/veilles/{veille}/toggle', [VeilleController::class, 'toggle'])->name('veilles.toggle');
    Route::delete('/veilles/{veille}', [VeilleController::class, 'destroy'])->name('veilles.destroy');
});

require __DIR__.'/auth.php';
