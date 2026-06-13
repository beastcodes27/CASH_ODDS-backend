<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sport;
use Illuminate\Http\Request;

class SportController extends Controller
{
    public function index()
    {
        $sports = Sport::active()
            ->withCount('tips')
            ->orderBy('name')
            ->get();

        return response()->json($sports);
    }

    public function show(Sport $sport)
    {
        return response()->json($sport->load(['leagues' => function ($query) {
            $query->active()->orderBy('name');
        }]));
    }
}
