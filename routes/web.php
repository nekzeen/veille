<?php

use App\Http\Controllers\ArchiveController;
use App\Http\Controllers\CatalogueController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicShareController;
use App\Http\Controllers\SourceDetectionController;
use App\Http\Controllers\UserExclusionController;
use App\Http\Controllers\UserSourceController;
use App\Http\Controllers\VeilleArticleController;
use App\Http\Controllers\VeilleController;
use App\Http\Controllers\VeilleShareController;
use App\Http\Middleware\ArticleCsp;
use App\Http\Middleware\ResolveShareToken;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Pages légales publiques (M12.15) — version affichée, contenu provisoire.
Route::get('/terms', fn () => view('pages.terms', ['version' => config('legal.terms_version')]))
    ->name('terms');
Route::get('/privacy', fn () => view('pages.privacy', ['version' => config('legal.privacy_version')]))
    ->name('privacy');

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/sources/catalogue', [CatalogueController::class, 'index'])->name('sources.catalogue');
    Route::get('/sources', [UserSourceController::class, 'index'])->name('sources.index');
    Route::post('/sources/{source}/subscribe', [UserSourceController::class, 'store'])->name('sources.subscribe');
    Route::post('/sources/personal', [UserSourceController::class, 'storePersonal'])->name('sources.personal');
    // Détection et prévisualisation d'URL (M8.3) — JSON, sans persistance.
    Route::middleware('throttle:source-detection')->group(function () {
        Route::post('/sources/detect', [SourceDetectionController::class, 'detect'])->name('sources.detect');
        Route::post('/sources/preview', [SourceDetectionController::class, 'preview'])->name('sources.preview');
        Route::post('/sources/confirm', [UserSourceController::class, 'storeDetected'])->name('sources.confirm');
    });
    Route::delete('/sources/{userSource}', [UserSourceController::class, 'destroy'])->name('sources.unsubscribe');
    Route::post('/sources/{userSource}/reactivate', [UserSourceController::class, 'reactivate'])
        ->name('sources.reactivate');
    Route::post('/sources/{userSource}/retry', [UserSourceController::class, 'retry'])
        ->name('sources.retry');

    Route::get('/veilles', [VeilleController::class, 'index'])->name('veilles.index');
    Route::get('/veilles/create', [VeilleController::class, 'create'])->name('veilles.create');
    Route::post('/veilles', [VeilleController::class, 'store'])->name('veilles.store');
    Route::get('/veilles/{veille}', [VeilleController::class, 'show'])->name('veilles.show');
    Route::get('/veilles/{veille}/edit', [VeilleController::class, 'edit'])->name('veilles.edit');
    Route::put('/veilles/{veille}', [VeilleController::class, 'update'])->name('veilles.update');
    Route::patch('/veilles/{veille}/toggle', [VeilleController::class, 'toggle'])->name('veilles.toggle');
    Route::get('/exclusions', [UserExclusionController::class, 'index'])->name('exclusions.index');
    Route::post('/exclusions', [UserExclusionController::class, 'store'])->name('exclusions.store');
    Route::put('/exclusions/{exclusion}', [UserExclusionController::class, 'update'])->name('exclusions.update');
    Route::patch('/exclusions/{exclusion}/toggle', [UserExclusionController::class, 'toggle'])->name('exclusions.toggle');
    Route::delete('/exclusions/{exclusion}', [UserExclusionController::class, 'destroy'])->name('exclusions.destroy');
    Route::get('/shares', [VeilleShareController::class, 'index'])->name('shares.index');
    Route::post('/shares', [VeilleShareController::class, 'store'])->name('shares.store');
    Route::patch('/shares/{share}', [VeilleShareController::class, 'update'])->name('shares.update');
    Route::post('/shares/{share}/rotate', [VeilleShareController::class, 'rotate'])->name('shares.rotate');
    Route::delete('/shares/{share}', [VeilleShareController::class, 'destroy'])->name('shares.destroy');
    Route::delete('/veilles/{veille}', [VeilleController::class, 'destroy'])->name('veilles.destroy');

    // Lecture des articles (M1.12) — CSP renforcée sur les deux routes.
    Route::middleware(ArticleCsp::class)->group(function () {
        Route::get('/veilles/{veille}/articles', [VeilleArticleController::class, 'index'])
            ->name('veilles.articles.index');
        Route::get('/veilles/{veille}/articles/{article}', [VeilleArticleController::class, 'show'])
            ->name('veilles.articles.show');
        Route::post('/veilles/{veille}/articles/read-all', [VeilleArticleController::class, 'markAllRead'])
            ->name('veilles.articles.read-all');
        Route::put('/veilles/{veille}/articles/{article}/read', [VeilleArticleController::class, 'markRead'])
            ->name('veilles.articles.read');
        Route::delete('/veilles/{veille}/articles/{article}', [VeilleArticleController::class, 'detach'])
            ->name('veilles.articles.detach');
        Route::delete('/veilles/{veille}/articles/{article}/read', [VeilleArticleController::class, 'markUnread'])
            ->name('veilles.articles.unread');
        Route::put('/veilles/{veille}/articles/{article}/favorite', [VeilleArticleController::class, 'markFavorite'])
            ->name('veilles.articles.favorite');
        Route::delete('/veilles/{veille}/articles/{article}/favorite', [VeilleArticleController::class, 'removeFavorite'])
            ->name('veilles.articles.unfavorite');
        Route::put('/veilles/{veille}/articles/{article}/note', [VeilleArticleController::class, 'saveNote'])
            ->name('veilles.articles.note');
        Route::delete('/veilles/{veille}/articles/{article}/note', [VeilleArticleController::class, 'deleteNote'])
            ->name('veilles.articles.note.delete');
        Route::put('/veilles/{veille}/articles/{article}/archive', [VeilleArticleController::class, 'archiveArticle'])
            ->name('veilles.articles.archive');
        Route::delete('/veilles/{veille}/articles/{article}/archive', [VeilleArticleController::class, 'unarchiveArticle'])
            ->name('veilles.articles.unarchive');
        Route::put('/veilles/{veille}/articles/{article}/category', [VeilleArticleController::class, 'setCategory'])
            ->name('veilles.articles.category');
        Route::delete('/veilles/{veille}/articles/{article}/category', [VeilleArticleController::class, 'clearCategory'])
            ->name('veilles.articles.category.delete');

        // « Mes catégories » : CRUD + navigation par catégorie.
        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::patch('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
        Route::get('/categories/{category}/articles', [CategoryController::class, 'articles'])
            ->name('categories.articles');
        Route::put('/articles/{article}/category', [CategoryController::class, 'setCategory'])
            ->name('articles.category');
        Route::delete('/articles/{article}/category', [CategoryController::class, 'clearCategory'])
            ->name('articles.category.delete');

        // Espace personnel (§4.D-bis) : favoris et lecture autonome.
        Route::get('/favoris', [FavoriteController::class, 'index'])->name('favorites.index');
        Route::get('/articles/{article}', [FavoriteController::class, 'show'])->name('articles.show');
        Route::put('/articles/{article}/favorite', [FavoriteController::class, 'markFavorite'])
            ->name('articles.favorite');
        Route::delete('/articles/{article}/favorite', [FavoriteController::class, 'removeFavorite'])
            ->name('articles.unfavorite');
        Route::put('/articles/{article}/note', [FavoriteController::class, 'saveNote'])
            ->name('articles.note');
        Route::delete('/articles/{article}/note', [FavoriteController::class, 'deleteNote'])
            ->name('articles.note.delete');
        Route::get('/archives', [ArchiveController::class, 'index'])->name('archives.index');
        Route::put('/articles/{article}/archive', [ArchiveController::class, 'archive'])
            ->name('articles.archive');
        Route::delete('/articles/{article}/archive', [ArchiveController::class, 'unarchive'])
            ->name('articles.unarchive');
    });
});

// Consultation publique en lecture seule par jeton (M10.4) — hors auth.
Route::middleware(['throttle:60,1', ResolveShareToken::class])->group(function () {
    Route::get('/shared/{token}', [PublicShareController::class, 'index'])->name('shared.index');
    Route::get('/shared/{token}/veilles/{veille}', [PublicShareController::class, 'veille'])->name('shared.veille');
    Route::get('/shared/{token}/articles/{article}', [PublicShareController::class, 'article'])->name('shared.article');
});

require __DIR__.'/auth.php';
