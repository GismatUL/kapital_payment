Route::match(['get','post'],'/kapital/approve/{id}',[App\Http\Controllers\Account\PaymentController::class, 'approveUrl'])->name('approve');
Route::match(['get','post'],'/kapital/decline/{id}',[App\Http\Controllers\Account\PaymentController::class, 'declineUrl'])->name('decline');
Route::match(['get','post'],'/kapital/cancel/{id}',[App\Http\Controllers\Account\PaymentController::class, 'cancelUrl'])->name('cancel');
