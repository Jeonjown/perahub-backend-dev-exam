<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\Partners;
use App\Http\Middleware\Purpose;
use App\Http\Middleware\Occupation;
use App\Http\Middleware\EmploymentNature;
use App\Http\Middleware\SourceOfFund;
use App\Http\Controllers\API\AuthenticationController;
use App\Http\Middleware\Relationship;

use App\Http\Controllers\API\TransactionController;


// Authentication routes
Route::post('/register', [AuthenticationController::class, 'register']);
Route::post('/login', [AuthenticationController::class, 'login']);
Route::middleware('auth:sanctum')->group(function () {
  Route::post('/logout', [AuthenticationController::class, 'logout']);
});

// transaksyon
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/inquire', [TransactionController::class, 'inquire'])
         ->middleware(Partners::class);
    Route::get('/logs', [TransactionController::class, 'getLogs']);
    Route::get('/transactions', [TransactionController::class, 'getTransactions']);

});








// middleware test only
Route::post('/test-middleware', function (Request $request) {
    return response()->json([
        'message' => 'All middleware validations passed!',
        'partner_info' => $request->partner_info ?? null,
        'purpose_info' => $request->purpose_info ?? null,
        'occupation_info' => $request->occupation_info ?? null,
        'employment_nature_info' => $request->employment_nature_info ?? null,
        'source_of_fund_info' => $request->source_of_fund_info ?? null,
        'relationship_info' => $request->relationship_info ?? null,
    ]);
})->middleware([
    Partners::class,
    Purpose::class,
    Occupation::class,
    EmploymentNature::class,
    SourceOfFund::class,
    Relationship::class,

]);
