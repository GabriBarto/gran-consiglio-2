<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Resources\SellerResource;
use App\Models\Seller;
use App\Services\Seller\Geocoder;
use App\Services\Seller\VatChecker;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    /** Stato dell'onboarding: cosa manca e se si può pubblicare. */
    public function show(Request $request): JsonResponse
    {
        return response()->json(SellerResource::forOwner($request->user()));
    }

    /** Crea o aggiorna i dati dell'attività. Il primo invio mette l'account "in verifica". */
    public function update(Request $request, Geocoder $geocoder, VatChecker $vatChecker): JsonResponse
    {
        $user = $request->user();
        $seller = $user->seller;

        if ($seller && ! $seller->isEditable()) {
            ApiError::throw('PROFILE_LOCKED', 'I dati di un\'attività approvata non si modificano da qui.', 403);
        }

        $request->merge(['vat_number' => $this->normalizeVat((string) $request->input('vat_number'))]);

        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:150'],
            'vat_number' => ['required', 'regex:/^IT\d{11}$/', Rule::unique('sellers', 'vat_number')->ignore($seller?->id)],
            'phone' => ['required', 'regex:/^\+?[0-9 ]{6,20}$/'],
            'address' => ['required', 'string', 'max:255'],
            'opens_at' => ['required', 'date_format:H:i'],
            'closes_at' => ['required', 'date_format:H:i', 'after:opens_at'],
            'pickup_from' => ['required', 'date_format:H:i', 'after_or_equal:opens_at'],
            'pickup_to' => ['required', 'date_format:H:i', 'after:pickup_from', 'before_or_equal:closes_at'],
        ]);

        // Servizi esterni solo se il dato è cambiato: meno chiamate e meno attese
        $vatChanged = ! $seller || $seller->vat_number !== $data['vat_number'];
        $addressChanged = ! $seller || $seller->address !== $data['address'];

        $vatStatus = $vatChanged ? $vatChecker->check($data['vat_number']) : $seller->vat_check_status;
        $place = $addressChanged ? $geocoder->locate($data['address']) : null;

        $seller ??= new Seller;
        $seller->fill($data);
        if ($place) {
            $seller->latitude = $place['lat'];
            $seller->longitude = $place['lng'];
            $seller->city = $place['city'];
        }
        $seller->vat_check_status = $vatStatus;

        $created = ! $seller->exists;
        if ($created) {
            $seller->user_id = $user->id;
            $seller->verification_status = Seller::STATUS_PENDING;
        }
        $seller->save();

        return response()->json(SellerResource::forOwner($user->refresh()), $created ? 201 : 200);
    }

    /** Dopo un rifiuto: rimette la richiesta in coda per l'admin. */
    public function resubmit(Request $request): JsonResponse
    {
        $user = $request->user();
        $seller = $user->seller;

        if (! $seller || $seller->verification_status !== Seller::STATUS_REJECTED) {
            ApiError::throw('INVALID_STATE', 'Non c\'è nessuna richiesta rifiutata da reinviare.', 409);
        }
        if ($seller->documents()->doesntExist()) {
            ApiError::throw('NO_DOCUMENTS', 'Allega almeno un documento dell\'attività.', 422);
        }

        $seller->forceFill(['verification_status' => Seller::STATUS_PENDING, 'verification_note' => null])->save();

        return response()->json(SellerResource::forOwner($user->refresh()));
    }

    /** "it12 345 678 901" → "IT12345678901"; 11 cifre senza prefisso valgono come italiane. */
    private function normalizeVat(string $vat): string
    {
        $vat = strtoupper(preg_replace('/\s+/', '', $vat) ?? '');

        return preg_match('/^\d{11}$/', $vat) ? 'IT'.$vat : $vat;
    }
}
