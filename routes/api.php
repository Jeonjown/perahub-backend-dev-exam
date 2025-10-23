

<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthenticationController;


Route::post('/register', [AuthenticationController::class, 'register']);
Route::post('/login', [AuthenticationController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthenticationController::class, 'logout']);
});
