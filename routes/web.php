<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['app' => 'FlashPay API', 'status' => 'ok']));

/*
|--------------------------------------------------------------------------
| Admin SPA (Vue) — servie par Laravel lui-même sous /admin/*
|--------------------------------------------------------------------------
| Toute route commençant par /admin renvoie la même vue Blade ; le routeur
| Vue (resources/js/admin/router/index.js, history base '/admin') prend
| ensuite le relais côté client. L'API reste séparée sous /api/* (voir
| routes/api.php) et n'est jamais interceptée par cette règle.
*/
Route::get('/admin/{any?}', fn () => view('admin'))
    ->where('any', '.*')
    ->name('admin.spa');

// Pages publiques : checkout e-commerce, liens de paiement, cadeaux, doc API (§3.2.2, §3.5.1, §4.7)
Route::get('/checkout/{id}', [\App\Http\Controllers\PublicPagesController::class, 'checkout'])->where('id', 'pi_[a-z0-9]+');
Route::post('/checkout/{id}', [\App\Http\Controllers\PublicPagesController::class, 'checkoutSubmit'])->where('id', 'pi_[a-z0-9]+')->middleware('throttle:20,1');
Route::get('/p/{token}', [\App\Http\Controllers\PublicPagesController::class, 'paymentLink']);
Route::get('/g/{code}', [\App\Http\Controllers\PublicPagesController::class, 'gift']);
Route::get('/d/{reference}', [\App\Http\Controllers\PublicPagesController::class, 'moneyRequest'])->where('reference', 'DM-[A-Za-z0-9]+');
Route::get('/docs/api-ecommerce', [\App\Http\Controllers\PublicPagesController::class, 'apiDocs']);

// Reçus de transaction et médias du chat : liens temporaires signés
Route::get('/cagnotte/{split}', [\App\Http\Controllers\Api\ClientFeaturesController::class, 'splitReceiptPage'])->name('split.receipt')->middleware('signed')->whereNumber('split');
Route::get('/r-batch', [\App\Http\Controllers\Api\ReceiptController::class, 'batch'])->name('receipt.batch')->middleware('signed');
Route::get('/r/{transaction}', [\App\Http\Controllers\Api\ReceiptController::class, 'show'])->name('receipt.show')->middleware('signed');
Route::get('/chat-media/{message}', [\App\Http\Controllers\Api\ChatController::class, 'signedFile'])->name('chat.media')->middleware('signed')->whereNumber('message');
