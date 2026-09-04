<?php

use App\Http\Controllers\Api\AnalyticsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\MailingController;
use App\Http\Controllers\Api\BotController;
use App\Http\Controllers\Api\TimerController;
use App\Http\Controllers\Api\TaskController;
use App\Models\Lead;
use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log; 


Route::post('/webhooks/whatsapp', [BotController::class, 'greenWebhook']);


Route::post('/login', [AuthController::class, 'login']);
Route::post('/green/webhook', [BotController::class, 'greenWebhook']);
Route::post('/users', [AuthController::class, 'register']); //регистрация менеджера





// Защищенные маршруты (только для авторизованных)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::get('/users', [AuthController::class, 'index']); // Список менеджеров
    Route::delete('/users/{user}', [AuthController::class, 'destroy']);
    Route::get('/admin/timers/today', [TimerController::class, 'adminToday']);
    Route::get('/admin/timers/monthly/{manager}/export', [TimerController::class, 'exportReport']);
    Route::get('/admin/timers/monthly/{manager}', [TimerController::class, 'adminMonthlyReport']);
    Route::get('/timer/today', [TimerController::class, 'myToday']);
    Route::post('/timer/action', [TimerController::class, 'action']);
    Route::get('/tasks', [TaskController::class, 'index']);
    Route::post('/tasks', [TaskController::class, 'store']);
    Route::post('/tasks/{task}/start', [TaskController::class, 'start']);
    Route::post('/tasks/{task}/complete', [TaskController::class, 'complete']);
    Route::post('/tasks/{task}/comment', [TaskController::class, 'comment']);
    Route::delete('/tasks/{task}', [TaskController::class, 'destroy']);
    Route::get('/notifications', [TaskController::class, 'notifications']);
    Route::post('/notifications/{notification}/read', [TaskController::class, 'readNotification']);
    Route::get('/admin/analytics', [AnalyticsController::class, 'index']);
    Route::get('/admin/analytics/export', [AnalyticsController::class, 'export']);
    Route::get('/leads/export', [LeadController::class, 'export']);
    Route::post('/leads/{lead}/complete', [LeadController::class, 'completeAction']);
    Route::apiResource('leads', LeadController::class);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/mailings', [MailingController::class, 'index']);
    Route::post('/mailings', [MailingController::class, 'store']);
    Route::delete('/mailings', [MailingController::class, 'destroyAll']); 
    Route::post('/leads/{lead}/take', [LeadController::class, 'take']);


    // Route::get('/leads/{lead}/messages', function(Lead $lead) {
    //     return $lead->messages()->orderBy('created_at', 'asc')->get();
    // });



    // Route::post('/leads/{lead}/send', function(Request $request, Lead $lead) {
    //     $request->validate(['text' => 'required|string']);
    //     $messageText = $request->input('text');

    //     $idInstance = env('GREEN_API_ID_INSTANCE'); 
    //     $apiToken = env('GREEN_API_TOKEN');
    //     $url = "https://api.green-api.com/waInstance{$idInstance}/sendMessage/{$apiToken}";
    //     $phone = preg_replace('/[^0-9]/', '', $lead->phone);

    //     try {
    //     $response = Http::post($url, [
    //         'chatId' => "{$phone}@c.us",
    //         'message' => $messageText,
    //     ]);

    //     if ($response->successful()) {
    //         $message = \App\Models\Message::create([
    //             'lead_id' => $lead->id,
    //             'text' => $messageText,
    //             'direction' => 'out',
    //             'external_id' => $response->json('idMessage'),
    //             'status' => 'sent',
    //         ]);

    //         return response()->json(['success' => true, 'message' => $message]);
    //     }
    // } catch (\Exception $e) {
    //     return response()->json(['error' => $e->getMessage()], 500);
    // }
    // });

});


// http://localhost:8000/api/leads?only_my=1&today=1   -  ЛИДЫ ДЛЯ МЕНЕДЖЕРА НА СЕГОДНЯ 
// php artisan queue:work
