<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\PollController;
use App\Livewire\CreatePoll;
use App\Livewire\ManagePoll;
use App\Livewire\VotePoll;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/polls');

Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
});

// Reachable while signed in too, so a signed-in user can (re)connect their calendar.
Route::get('/auth/google', [GoogleController::class, 'redirect'])->name('auth.google');
Route::get('/auth/google/callback', [GoogleController::class, 'callback']);
Route::post('/logout', [GoogleController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/polls', [PollController::class, 'index'])->name('dashboard');
    Route::livewire('/polls/create', CreatePoll::class)->name('polls.create');
    Route::livewire('/polls/{poll}', ManagePoll::class)->name('polls.show');
});

// Public voting page: no login. {token} identifies an invited or returning participant.
Route::livewire('/p/{poll}/{token?}', VotePoll::class)->name('polls.vote');
