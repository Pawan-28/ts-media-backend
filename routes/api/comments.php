<?php

use App\Http\Controllers\Api\ArticleCommentController;
use Illuminate\Support\Facades\Route;

$slug = '[^/.]+';

Route::get('articles/{slug}/comments', [ArticleCommentController::class, 'index'])->where('slug', $slug);
Route::post('articles/{slug}/comments', [ArticleCommentController::class, 'store'])
    ->where('slug', $slug)->middleware(['api.auth', 'throttle:10,1']);
Route::delete('comments/{id}', [ArticleCommentController::class, 'destroy'])->whereNumber('id')->middleware('api.auth');
