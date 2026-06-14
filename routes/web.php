<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'message' => 'CASH ODDS API',
        'version' => '1.0.0',
        'status' => 'running',
    ]);
});
