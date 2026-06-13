<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tip;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $data = [
            'user' => $user->load(['activeSubscription.plan']),
            'stats' => [
                'total_tips' => Tip::count(),
                'won_tips' => Tip::won()->count(),
                'win_rate' => $this->calculateWinRate(),
            ],
            'today_tips' => Tip::with(['tipster', 'sport'])
                ->today()
                ->published()
                ->limit(5)
                ->get(),
            'featured_tips' => Tip::with(['tipster', 'sport'])
                ->featured()
                ->published()
                ->limit(5)
                ->get(),
        ];

        if ($user->isTipster()) {
            $data['tipster_stats'] = [
                'my_tips' => $user->tips()->count(),
                'won' => $user->tips()->won()->count(),
                'lost' => $user->tips()->lost()->count(),
                'win_rate' => $this->calculateTipsterWinRate($user->id),
            ];
        }

        return response()->json($data);
    }

    private function calculateWinRate(): float
    {
        $won = Tip::won()->count();
        $finished = Tip::whereIn('status', ['won', 'lost'])->count();

        return $finished > 0 ? round(($won / $finished) * 100, 2) : 0;
    }

    private function calculateTipsterWinRate(int $tipsterId): float
    {
        $won = Tip::where('tipster_id', $tipsterId)->won()->count();
        $finished = Tip::where('tipster_id', $tipsterId)
            ->whereIn('status', ['won', 'lost'])
            ->count();

        return $finished > 0 ? round(($won / $finished) * 100, 2) : 0;
    }
}
