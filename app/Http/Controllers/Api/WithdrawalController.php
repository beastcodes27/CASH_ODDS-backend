<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Models\Withdrawal;
use Illuminate\Http\Request;

class WithdrawalController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $withdrawals = $user->withdrawals()
            ->with('paymentMethod')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json($withdrawals);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'payment_method_id' => 'required|exists:payment_methods,id',
            'amount' => 'required|numeric|min:10',
            'notes' => 'nullable|string',
        ]);

        $paymentMethod = PaymentMethod::findOrFail($validated['payment_method_id']);

        if ($paymentMethod->user_id !== $user->id) {
            return response()->json([
                'message' => 'Invalid payment method.',
            ], 403);
        }

        // Check if user has sufficient balance
        if ($user->balance < $validated['amount']) {
            return response()->json([
                'message' => 'Insufficient balance.',
            ], 400);
        }

        $fee = $this->calculateFee($validated['amount']);

        $withdrawal = Withdrawal::create([
            'user_id' => $user->id,
            'payment_method_id' => $paymentMethod->id,
            'reference' => Withdrawal::generateReference(),
            'amount' => $validated['amount'],
            'fee' => $fee,
            'status' => 'pending',
            'notes' => $validated['notes'] ?? null,
        ]);

        // Deduct from balance
        $user->decrement('balance', $validated['amount']);

        return response()->json([
            'withdrawal' => $withdrawal->load('paymentMethod'),
            'message' => 'Withdrawal request submitted successfully.',
        ], 201);
    }

    public function show(Request $request, Withdrawal $withdrawal)
    {
        $user = $request->user();

        if ($withdrawal->user_id !== $user->id && !$user->isAdmin()) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        return response()->json($withdrawal->load('paymentMethod'));
    }

    public function cancel(Request $request, Withdrawal $withdrawal)
    {
        $user = $request->user();

        if ($withdrawal->user_id !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        if (!$withdrawal->isPending()) {
            return response()->json([
                'message' => 'Cannot cancel withdrawal that is not pending.',
            ], 400);
        }

        // Refund the balance
        $user->increment('balance', $withdrawal->amount);

        $withdrawal->update(['status' => 'cancelled']);

        return response()->json([
            'message' => 'Withdrawal cancelled successfully.',
        ]);
    }

    public function pending(Request $request)
    {
        if (!$request->user()->isAdmin()) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        $withdrawals = Withdrawal::with(['user', 'paymentMethod'])
            ->pending()
            ->orderBy('created_at')
            ->paginate(20);

        return response()->json($withdrawals);
    }

    public function process(Request $request, Withdrawal $withdrawal)
    {
        if (!$request->user()->isAdmin()) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        if (!$withdrawal->isPending()) {
            return response()->json([
                'message' => 'Withdrawal is not pending.',
            ], 400);
        }

        $withdrawal->process($request->user()->id);

        return response()->json([
            'withdrawal' => $withdrawal,
            'message' => 'Withdrawal is now being processed.',
        ]);
    }

    public function complete(Request $request, Withdrawal $withdrawal)
    {
        if (!$request->user()->isAdmin()) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        $withdrawal->complete();

        return response()->json([
            'withdrawal' => $withdrawal,
            'message' => 'Withdrawal completed successfully.',
        ]);
    }

    public function reject(Request $request, Withdrawal $withdrawal)
    {
        if (!$request->user()->isAdmin()) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        $validated = $request->validate([
            'reason' => 'required|string',
        ]);

        // Refund the balance
        $withdrawal->user->increment('balance', $withdrawal->amount);

        $withdrawal->reject($validated['reason']);

        return response()->json([
            'withdrawal' => $withdrawal,
            'message' => 'Withdrawal rejected.',
        ]);
    }

    private function calculateFee(float $amount): float
    {
        // Simple fee calculation - can be made more complex
        if ($amount < 50) {
            return 2;
        } elseif ($amount < 200) {
            return 5;
        }
        return $amount * 0.025; // 2.5% for larger amounts
    }
}
