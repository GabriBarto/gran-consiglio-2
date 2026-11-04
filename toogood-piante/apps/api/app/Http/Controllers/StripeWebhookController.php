<?php

namespace App\Http\Controllers;

use App\Services\Orders\PaymentEventHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Webhook di Stripe. Pubblico per forza (lo chiama Stripe), quindi di ogni richiesta si verifica la firma
 * con il segreto del webhook: senza firma valida non si fa nulla.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentEventHandler $handler): JsonResponse
    {
        try {
            // Serve il corpo esatto, byte per byte: la firma si calcola su quello
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                (string) config('toogood.stripe.webhook_secret'),
            );
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return response()->json(['code' => 'INVALID_SIGNATURE', 'message' => 'Firma non valida.'], 400);
        }

        $outcome = $handler->handle($event->toArray());

        return response()->json(['received' => true, 'outcome' => $outcome]);
    }
}
