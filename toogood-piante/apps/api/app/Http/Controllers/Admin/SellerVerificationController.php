<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\SellerResource;
use App\Mail\SellerVerificationMail;
use App\Models\Seller;
use App\Models\SellerDocument;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SellerVerificationController extends Controller
{
    /** Coda delle richieste: di default quelle in attesa, dalla più vecchia. */
    public function index(Request $request): JsonResponse
    {
        $status = $request->validate([
            'status' => ['nullable', Rule::in([Seller::STATUS_PENDING, Seller::STATUS_APPROVED, Seller::STATUS_REJECTED, Seller::STATUS_SUSPENDED])],
        ])['status'] ?? Seller::STATUS_PENDING;

        $sellers = Seller::with(['user', 'documents'])
            ->where('verification_status', $status)
            ->orderBy('updated_at')
            ->get();

        return response()->json(['data' => $sellers->map(fn (Seller $s) => SellerResource::forAdmin($s))->all()]);
    }

    public function show(Seller $seller): JsonResponse
    {
        return response()->json(SellerResource::forAdmin($seller->load(['user', 'documents'])));
    }

    /** Il documento passa dall'API (solo admin): lo storage non ha indirizzi pubblici. */
    public function document(Seller $seller, SellerDocument $document): StreamedResponse
    {
        if ($document->seller_id !== $seller->id) {
            ApiError::throw('NOT_FOUND', 'Documento non trovato.', 404);
        }

        return Storage::disk('s3')->response($document->storage_key, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function approve(Seller $seller): JsonResponse
    {
        $this->assertPending($seller);

        if ($seller->documents()->doesntExist()) {
            ApiError::throw('NO_DOCUMENTS', 'Il venditore non ha allegato nessun documento.', 422);
        }

        $seller->forceFill([
            'verification_status' => Seller::STATUS_APPROVED,
            'verification_note' => null,
            'verified_at' => now(),
        ])->save();

        Mail::to($seller->user->email)->send(new SellerVerificationMail($seller, true));

        return response()->json(SellerResource::forAdmin($seller->load(['user', 'documents'])));
    }

    public function reject(Request $request, Seller $seller): JsonResponse
    {
        $this->assertPending($seller);

        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:1000']]);

        $seller->forceFill([
            'verification_status' => Seller::STATUS_REJECTED,
            'verification_note' => $data['note'],
            'verified_at' => null,
        ])->save();

        Mail::to($seller->user->email)->send(new SellerVerificationMail($seller, false, $data['note']));

        return response()->json(SellerResource::forAdmin($seller->load(['user', 'documents'])));
    }

    private function assertPending(Seller $seller): void
    {
        if ($seller->verification_status !== Seller::STATUS_PENDING) {
            ApiError::throw('INVALID_STATE', 'La richiesta non è in attesa di verifica.', 409);
        }
    }
}
