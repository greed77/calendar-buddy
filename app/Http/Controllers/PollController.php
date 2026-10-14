<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class PollController extends Controller
{
    public function index(Request $request): View
    {
        return view('dashboard', [
            'polls' => $request->user()->polls()
                ->withCount(['slots', 'participants as responses_count' => fn ($q) => $q->whereNotNull('responded_at')])
                ->latest()
                ->get(),
        ]);
    }
}
