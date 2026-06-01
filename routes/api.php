<?php

use App\Actions\Attachment\AbortAttachmentUploadAction;
use App\Actions\Attachment\CompleteAttachmentUploadAction;
use App\Actions\Attachment\CreateAttachmentUploadAction;
use App\Actions\Attachment\ProxyAttachmentUploadAction;
use App\Actions\Attachment\SignAttachmentUploadPartAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->prefix('attachments')->group(function () {
    Route::post('uploads', CreateAttachmentUploadAction::class)
        ->middleware('throttle:30,1')
        ->name('attachments.uploads.create');
    Route::post('uploads/{upload}/proxy', ProxyAttachmentUploadAction::class)
        ->middleware('throttle:60,1')
        ->name('attachments.uploads.proxy');
    Route::post('uploads/{upload}/parts', SignAttachmentUploadPartAction::class)
        ->middleware('throttle:120,1')
        ->name('attachments.uploads.parts');
    Route::post('uploads/{upload}/complete', CompleteAttachmentUploadAction::class)
        ->middleware('throttle:60,1')
        ->name('attachments.uploads.complete');
    Route::delete('uploads/{upload}', AbortAttachmentUploadAction::class)
        ->middleware('throttle:60,1')
        ->name('attachments.uploads.abort');
});

Route::prefix('visitor/attachments')->group(function () {
    Route::post('uploads', CreateAttachmentUploadAction::class)
        ->middleware('throttle:30,1')
        ->name('visitor.attachments.uploads.create');
    Route::post('uploads/{upload}/proxy', ProxyAttachmentUploadAction::class)
        ->middleware('throttle:60,1')
        ->name('visitor.attachments.uploads.proxy');
    Route::post('uploads/{upload}/parts', SignAttachmentUploadPartAction::class)
        ->middleware('throttle:120,1')
        ->name('visitor.attachments.uploads.parts');
    Route::post('uploads/{upload}/complete', CompleteAttachmentUploadAction::class)
        ->middleware('throttle:60,1')
        ->name('visitor.attachments.uploads.complete');
    Route::delete('uploads/{upload}', AbortAttachmentUploadAction::class)
        ->middleware('throttle:60,1')
        ->name('visitor.attachments.uploads.abort');
});
