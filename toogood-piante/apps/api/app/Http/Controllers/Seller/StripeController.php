<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Resources\SellerResource;
use App\Services\Stripe\ConnectService;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\ApiErrorException;

class StripeController extends Controller
{
    /** Crea (se serve) l'account Connect e restituisce l'indirizzo dove il venditore completa i dati. */
    public function onboarding(Request $request, ConnectService $connect): JsonResponse
    {
        $seller = $request->user()->seller;

        if (! $seller) {
            ApiError::throw('PROFILE_REQUIRED', 'Compila prima i dati dell\'attività.', 409);
        }

        try {
            if (! $seller->stripe_account_id) {
                $seller->forceFill(['stripe_account_id' => $connect->createAccount($seller->load('user'))])->save();
            }

            return response()->json(['url' => $connect->onboardingUrl($seller->stripe_account_id)]);
        } catch (ApiErrorException) {
            ApiError::throw('STRIPE_UNAVAILABLE', 'Il servizio di pagamento non risponde. Riprova tra poco.', 502);
        }
    }

    /** Chiede a Stripe se l'onboarding è finito e aggiorna lo stato. L'app lo chiama quando il venditore torna. */
    public function sync(Request $request, ConnectService $connect): JsonResponse
    {
        $seller = $request->user()->seller;

        if ($seller?->stripe_account_id && ! $seller->stripeOnboarded()) {
            try {
                if ($connect->isOnboarded($seller->stripe_account_id)) {
                    $seller->forceFill(['stripe_onboarded_at' => now()])->save();
                }
            } catch (ApiErrorException) {
                ApiError::throw('STRIPE_UNAVAILABLE', 'Il servizio di pagamento non risponde. Riprova tra poco.', 502);
            }
        }

        return response()->json(SellerResource::forOwner($request->user()->refresh()));
    }
}
