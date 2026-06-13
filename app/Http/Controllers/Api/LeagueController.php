<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\League;
use Illuminate\Http\Request;

class LeagueController extends Controller
{
    public function index(Request $request)
    {
        $query = League::active()->with('sport');

        if ($request->has('sport_id')) {
            $query->where('sport_id', $request->sport_id);
        }

        if ($request->has('country')) {
            $query->where('country', $request->country);
        }

        $leagues = $query->orderBy('name')->get();

        return response()->json($leagues);
    }

    public function show(League $league)
    {
        return response()->json($league->load('sport'));
    }

    public function countries()
    {
        $countries = League::active()
            ->whereNotNull('country')
            ->distinct()
            ->pluck('country');

        return response()->json($countries);
    }
}
