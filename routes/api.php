<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SpeechToTextController;

Route::post('/upload', [SpeechToTextController::class, 'upload']);
