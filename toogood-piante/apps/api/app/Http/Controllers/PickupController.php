<?php

namespace App\Http\Controllers;

use App\Http\Resources\SellerOrderResource;
use App\Models\Order;
use App\Services\Orders\PickupService;
use App\Support\ApiError;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Ritiro con QR: il cliente mostra il suo biglietto, il venditore lo scansiona (US-C09, US-V15). */
class PickupController extends Controller
{
    /**
     * Il biglietto di un proprio ordine pagato: testo del QR, codice alternativo, finestra di validità e stato.
     * L'app lo salva sul telefono una volta caricato, così si mostra anche con poca connessione (US-C09 CA3).
     */
    public function show(Request $request, int $order, PickupService $pickup): JsonResponse
    {
        $model = Order::where('customer_id', $request->user()->id)->with('seller')->find($order)
            ?? ApiError::throw('NOT_FOUND', 'Ordine non trovato.', 404);

        if (! in_array($model->status, [Order::PAID, Order::PICKED_UP], true)) {
            ApiError::throw('INVALID_STATE', 'Il codice di ritiro c\'è solo per gli ordini pagati.', 409);
        }

        // Normalmente esiste già dal pagamento; per gli ordini pagati prima di questa funzione si crea adesso
        $token = $pickup->issue($model);
        $rome = fn ($at) => CarbonImmutable::instance($at)->setTimezone(config('toogood.timezone'))->toIso8601String();

        return response()->json([
            'order_id' => $model->id,
            'number' => $model->number(),
            'qr' => $pickup->qrPayload($token),
            'code' => $pickup->displayCode($token),
            'state' => $pickup->state($token),
            'valid_from' => $rome($token->valid_from),
            'valid_until' => $rome($token->valid_until),
            'used_at' => $token->used_at?->toIso8601String(),
            'shop' => ['business_name' => $model->seller->business_name, 'address' => $model->seller->address],
        ]);
    }

    /**
     * Il venditore scansiona il QR o digita il codice: se è valido, di questo negozio, nella fascia giusta e non già
     * usato, l'ordine risulta ritirato (con data e ora). Altrimenti un errore che dice perché (CA1).
     */
    public function scan(Request $request, PickupService $pickup): JsonResponse
    {
        $data = $request->validate([
            'qr' => ['nullable', 'required_without:code', 'string', 'max:500'],
            'code' => ['nullable', 'required_without:qr', 'string', 'max:30'],
        ]);

        $order = $pickup->scan($request->user(), $data['qr'] ?? null, $data['code'] ?? null);

        return response()->json(['result' => 'OK', 'order' => SellerOrderResource::make($order)]);
    }
}
