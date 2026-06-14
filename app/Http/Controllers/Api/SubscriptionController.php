<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function plans()
    {
        $plans = SubscriptionPlan::active()
            ->orderBy('price')
            ->get();

        return response()->json($plans);
    }

    public function showPlan(SubscriptionPlan $plan)
    {
        return response()->json($plan);
    }

    public function subscribe(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'plan_id' => 'required|exists:subscription_plans,id',
            'payment_method' => 'required|in:mpesa,card,bank,paypal,crypto',
        ]);

        $plan = SubscriptionPlan::findOrFail($validated['plan_id']);

        if (!$plan->is_active) {
            return response()->json([
                'message' => 'This subscription plan is not available.',
            ], 400);
        }

        // Check if user already has an active subscription
        if ($user->hasActiveSubscription()) {
            return response()->json([
                'message' => 'You already have an active subscription.',
            ], 400);
        }

        // Create subscription
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'starts_at' => now(),
            'ends_at' => now()->addDays($plan->duration_days),
            'status' => 'pending',
        ]);

        // Create payment record
        $payment = Payment::create([
            'user_id' => $user->id,
            'payable_type' => Subscription::class,
            'payable_id' => $subscription->id,
            'transaction_id' => Payment::generateReference(),
            'reference' => Payment::generateReference(),
            'type' => 'subscription',
            'method' => $validated['payment_method'],
            'amount' => $plan->price,
            'currency' => 'USD',
            'status' => 'pending',
        ]);

        return response()->json([
            'subscription' => $subscription->load('plan'),
            'payment' => $payment,
            'message' => 'Subscription initiated. Please complete payment.',
        ], 201);
    }

    public function mySubscription(Request $request)
    {
        $user = $request->user();

        $subscription = $user->subscriptions()
            ->with('plan')
            ->latest()
            ->first();

        if (!$subscription) {
            return response()->json([
                'message' => 'No subscription found.',
                'has_active' => false,
            ]);
        }

        return response()->json([
            'subscription' => $subscription,
            'has_active' => $subscription->isActive(),
            'days_remaining' => $subscription->daysRemaining(),
        ]);
    }

    public function history(Request $request)
    {
        $user = $request->user();

        $subscriptions = $user->subscriptions()
            ->with('plan')
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return response()->json($subscriptions);
    }

    public function renew(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'payment_method' => 'required|in:mpesa,card,bank,paypal,crypto',
        ]);

        $currentSubscription = $user->subscriptions()
            ->whereIn('status', ['active', 'expired'])
            ->latest()
            ->first();

        if (!$currentSubscription) {
            return response()->json([
                'message' => 'No previous subscription found. Please subscribe to a plan.',
            ], 400);
        }

        $plan = $currentSubscription->plan;

        // Create new subscription (renewal)
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'starts_at' => $currentSubscription->isActive() ? $currentSubscription->ends_at : now(),
            'ends_at' => $currentSubscription->isActive() 
                ? $currentSubscription->ends_at->addDays($plan->duration_days)
                : now()->addDays($plan->duration_days),
            'status' => 'pending',
        ]);

        // Create payment record
        $payment = Payment::create([
            'user_id' => $user->id,
            'payable_type' => Subscription::class,
            'payable_id' => $subscription->id,
            'transaction_id' => Payment::generateReference(),
            'reference' => Payment::generateReference(),
            'type' => 'subscription',
            'method' => $validated['payment_method'],
            'amount' => $plan->price,
            'currency' => 'USD',
            'status' => 'pending',
        ]);

        return response()->json([
            'subscription' => $subscription->load('plan'),
            'payment' => $payment,
            'message' => 'Renewal initiated. Please complete payment.',
        ], 201);
    }

    public function cancel(Request $request)
    {
        $user = $request->user();

        $subscription = $user->subscriptions()
            ->where('status', 'active')
            ->first();

        if (!$subscription) {
            return response()->json([
                'message' => 'No active subscription found.',
            ], 400);
        }

        $subscription->update(['status' => 'cancelled']);

        return response()->json([
            'message' => 'Subscription cancelled successfully.',
        ]);
    }

    public function checkExpiry()
    {
        // This method would typically be called by a scheduled command
        // Mark expired subscriptions
        $expired = Subscription::expired()
            ->where('status', 'active')
            ->update(['status' => 'expired']);

        return response()->json([
            'expired_count' => $expired,
            'message' => 'Expiry check completed.',
        ]);
    }
}
