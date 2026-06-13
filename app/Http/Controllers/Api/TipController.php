<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\League;
use App\Models\Sport;
use App\Models\Tip;
use Illuminate\Http\Request;

class TipController extends Controller
{
    public function index(Request $request)
    {
        $query = Tip::with(['tipster', 'sport', 'league'])
            ->where('match_date', '>=', now()->subDay());

        // Filter by sport
        if ($request->has('sport_id')) {
            $query->bySport($request->sport_id);
        }

        // Filter by league
        if ($request->has('league_id')) {
            $query->byLeague($request->league_id);
        }

        // Filter by odds type
        if ($request->has('odds_type')) {
            $query->where('odds_type', $request->odds_type);
        }

        // Filter by tipster
        if ($request->has('tipster_id')) {
            $query->where('tipster_id', $request->tipster_id);
        }

        // Filter premium/free
        if ($request->boolean('premium_only')) {
            $query->premium();
        } elseif ($request->boolean('free_only')) {
            $query->free();
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by date
        if ($request->has('date')) {
            $query->whereDate('match_date', $request->date);
        }

        // Filter upcoming
        if ($request->boolean('upcoming')) {
            $query->published();
        }

        // Filter featured
        if ($request->boolean('featured')) {
            $query->featured();
        }

        // Order by
        $orderBy = $request->get('order_by', 'match_date');
        $orderDirection = $request->get('order_direction', 'asc');
        $query->orderBy($orderBy, $orderDirection);

        $tips = $query->paginate($request->get('per_page', 15));

        return response()->json($tips);
    }

    public function show(Request $request, Tip $tip)
    {
        $user = $request->user();

        // Check if user can access premium tip
        if ($tip->is_premium && (!$user || !$user->canAccessPremiumTip($tip))) {
            return response()->json([
                'message' => 'This is a premium tip. Please subscribe to access.',
                'tip' => [
                    'id' => $tip->id,
                    'home_team' => $tip->home_team,
                    'away_team' => $tip->away_team,
                    'match_date' => $tip->match_date,
                    'is_premium' => true,
                ],
            ], 403);
        }

        return response()->json([
            'tip' => $tip->load(['tipster', 'sport', 'league']),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if (!$user->isTipster() && !$user->isAdmin()) {
            return response()->json([
                'message' => 'Only tipsters can create tips.',
            ], 403);
        }

        $validated = $request->validate([
            'sport_id' => 'required|exists:sports,id',
            'league_id' => 'required|exists:leagues,id',
            'home_team' => 'required|string|max:255',
            'away_team' => 'required|string|max:255',
            'match_date' => 'required|date|after:now',
            'prediction' => 'required|string|max:255',
            'odds_type' => 'required|in:1x2,over_under,btts,handicap,correct_score,other',
            'odds' => 'required|numeric|min:1',
            'confidence' => 'nullable|in:low,medium,high',
            'analysis' => 'nullable|string',
            'is_premium' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
        ]);

        $validated['tipster_id'] = $user->id;

        $tip = Tip::create($validated);

        return response()->json([
            'tip' => $tip->load(['tipster', 'sport', 'league']),
            'message' => 'Tip created successfully',
        ], 201);
    }

    public function update(Request $request, Tip $tip)
    {
        $user = $request->user();

        if ($tip->tipster_id !== $user->id && !$user->isAdmin()) {
            return response()->json([
                'message' => 'You can only update your own tips.',
            ], 403);
        }

        // Don't allow editing if match has started
        if ($tip->match_date <= now()) {
            return response()->json([
                'message' => 'Cannot edit tips for matches that have already started.',
            ], 403);
        }

        $validated = $request->validate([
            'prediction' => 'nullable|string|max:255',
            'odds' => 'nullable|numeric|min:1',
            'confidence' => 'nullable|in:low,medium,high',
            'analysis' => 'nullable|string',
            'is_premium' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
        ]);

        $tip->update($validated);

        return response()->json([
            'tip' => $tip->fresh(['tipster', 'sport', 'league']),
            'message' => 'Tip updated successfully',
        ]);
    }

    public function destroy(Request $request, Tip $tip)
    {
        $user = $request->user();

        if ($tip->tipster_id !== $user->id && !$user->isAdmin()) {
            return response()->json([
                'message' => 'You can only delete your own tips.',
            ], 403);
        }

        // Don't allow deletion if match has started
        if ($tip->match_date <= now()) {
            return response()->json([
                'message' => 'Cannot delete tips for matches that have already started.',
            ], 403);
        }

        $tip->delete();

        return response()->json([
            'message' => 'Tip deleted successfully',
        ]);
    }

    public function setResult(Request $request, Tip $tip)
    {
        $user = $request->user();

        if (!$user->isAdmin()) {
            return response()->json([
                'message' => 'Only admins can set match results.',
            ], 403);
        }

        $validated = $request->validate([
            'home_score' => 'required|integer|min:0',
            'away_score' => 'required|integer|min:0',
            'status' => 'required|in:won,lost,void',
        ]);

        $tip->update([
            'home_score' => $validated['home_score'],
            'away_score' => $validated['away_score'],
            'status' => $validated['status'],
        ]);

        return response()->json([
            'tip' => $tip->fresh(),
            'message' => 'Match result updated successfully',
        ]);
    }

    public function today(Request $request)
    {
        $tips = Tip::with(['tipster', 'sport', 'league'])
            ->today()
            ->published()
            ->orderBy('match_date')
            ->get();

        return response()->json($tips);
    }

    public function featured(Request $request)
    {
        $tips = Tip::with(['tipster', 'sport', 'league'])
            ->featured()
            ->published()
            ->orderBy('match_date')
            ->limit(10)
            ->get();

        return response()->json($tips);
    }

    public function bySport(Sport $sport)
    {
        $tips = Tip::with(['tipster', 'league'])
            ->bySport($sport->id)
            ->published()
            ->orderBy('match_date')
            ->paginate(15);

        return response()->json($tips);
    }

    public function byLeague(League $league)
    {
        $tips = Tip::with(['tipster', 'sport'])
            ->byLeague($league->id)
            ->published()
            ->orderBy('match_date')
            ->paginate(15);

        return response()->json($tips);
    }

    public function myTips(Request $request)
    {
        $user = $request->user();

        $tips = Tip::with(['sport', 'league'])
            ->where('tipster_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json($tips);
    }

    public function stats(Request $request)
    {
        $query = Tip::query();

        if ($request->has('tipster_id')) {
            $query->where('tipster_id', $request->tipster_id);
        }

        $stats = [
            'total' => $query->count(),
            'won' => (clone $query)->won()->count(),
            'lost' => (clone $query)->lost()->count(),
            'pending' => (clone $query)->pending()->count(),
            'win_rate' => 0,
        ];

        $finished = $stats['won'] + $stats['lost'];
        if ($finished > 0) {
            $stats['win_rate'] = round(($stats['won'] / $finished) * 100, 2);
        }

        return response()->json($stats);
    }
}
