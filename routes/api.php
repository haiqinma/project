<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AutomationController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

// 自动化 API 只接受 AK/SK 签名，不进入 Cookie/Session 请求链。
Route::prefix('automation')->middleware('automation.auth')->group(function () {
    Route::any('{method}/{action}', AutomationController::class);
});
