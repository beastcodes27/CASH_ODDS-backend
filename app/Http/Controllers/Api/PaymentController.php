<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $payments = $user->payments()
            ->with('payable')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json($payments);
    }

    public function show(Request $request, Payment $payment)
    {
        $user = $request->user();

        if ($payment->user_id !== $user->id && !$user->isAdmin()) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        return response()->json($payment->load('payable'));
    }

    public function verify(Request $request)
    {
        $validated = $request->validate([
            'reference' => 'required|string',
            'transaction_id' => 'nullable|string',
        ]);

        $payment = Payment::where('reference', $validated['reference'])
            ->orWhere('transaction_id', $validated['reference'])
            ->firstOrFail();

        // TODO: Implement actual payment gateway verification
        // This is a placeholder for the verification logic

        return response()->json([
            'payment' => $payment,
            'status' => $payment->status,
        ]);
    }

    public function callback(Request $request)
    {
        // This method handles payment gateway callbacks
        // TODO: Implement gateway-specific callback handling

        $validated = $request->validate([
            'reference' => 'required|string',
            'status' => 'required|string',
            'transaction_id' => 'nullable|string',
        ]);

        $payment = Payment::where('reference', $validated['reference'])->firstOrFail();

        if ($validated['status'] === 'success') {
            $payment->markAsCompleted();

            // Activate subscription if applicable
            if ($payment->payable_type === 'App\Models\Subscription') {
                $payment->payable->update(['status' => 'active']);
            }
        } else {
            $payment->markAsFailed($validated['status']);
        }

        return response()->json([
            'message' => 'Callback processed.',
        ]);
    }

    public function paymentMethods(Request $request)
    {
        $user = $request->user();

        $methods = $user->paymentMethods()->get();

        return response()->json($methods);
    }

    public function addPaymentMethod(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'type' => 'required|in:mpesa,card,bank,paypal',
            'provider' => 'required|string',
            'account_number' => 'required|string',
            'account_name' => 'nullable|string',
            'is_default' => 'nullable|boolean',
        ]);

        $validated['user_id'] = $user->id;
        $validated['last_four'] = substr($validated['account_number'], -4);

        // Mask the account number for storage
        $validated['account_number'] = encrypt($validated['account_number']);

        $method = PaymentMethod::create($validated);

        if ($validated['is_default'] ?? false) {
            $method->setAsDefault();
        }

        return response()->json([
            'method' => $method,
            'message' => 'Payment method added successfully.',
        ], 201);
    }

    public function removePaymentMethod(Request $request, PaymentMethod $paymentMethod)
    {
        $user = $request->user();

        if ($paymentMethod->user_id !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        $paymentMethod->delete();

        return response()->json([
            'message' => 'Payment method removed successfully.',
        ]);
    }

    public function setDefaultPaymentMethod(Request $request, PaymentMethod $paymentMethod)
    {
        $user = $request->user();

        if ($paymentMethod->user_id !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        $paymentMethod->setAsDefault();

        return response()->json([
            'message' => 'Default payment method updated.',
        ]);
    }
}
