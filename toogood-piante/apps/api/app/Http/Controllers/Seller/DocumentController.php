<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Resources\SellerResource;
use App\Models\Seller;
use App\Models\SellerDocument;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    private const MAX_DOCUMENTS = 5;

    public function store(Request $request): JsonResponse
    {
        $seller = $this->editableSeller($request);

        $request->validate([
            // Formati e peso: PDF/JPG/PNG fino a 5 MB. mimes controlla il contenuto del file, non solo il nome.
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        if ($seller->documents()->count() >= self::MAX_DOCUMENTS) {
            ApiError::throw('TOO_MANY_DOCUMENTS', 'Puoi allegare al massimo '.self::MAX_DOCUMENTS.' documenti.', 422);
        }

        $file = $request->file('file');
        // Nome scelto da noi: il nome inviato dal client non entra mai nel percorso
        $key = "seller-documents/{$seller->id}/".Str::uuid().'.'.$file->guessExtension();
        Storage::disk('s3')->putFileAs(dirname($key), $file, basename($key), 'private');

        $seller->documents()->create([
            'storage_key' => $key,
            'original_name' => Str::limit($file->getClientOriginalName(), 200, ''),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
        ]);

        return response()->json(SellerResource::forOwner($request->user()->refresh()), 201);
    }

    public function destroy(Request $request, SellerDocument $document): JsonResponse
    {
        $seller = $this->editableSeller($request);

        if ($document->seller_id !== $seller->id) {
            ApiError::throw('NOT_FOUND', 'Documento non trovato.', 404);
        }

        Storage::disk('s3')->delete($document->storage_key);
        $document->delete();

        return response()->json(SellerResource::forOwner($request->user()->refresh()));
    }

    private function editableSeller(Request $request): Seller
    {
        $seller = $request->user()->seller;

        if (! $seller) {
            ApiError::throw('PROFILE_REQUIRED', 'Compila prima i dati dell\'attività.', 409);
        }
        if (! $seller->isEditable()) {
            ApiError::throw('PROFILE_LOCKED', 'I documenti di un\'attività approvata non si modificano da qui.', 403);
        }

        return $seller;
    }
}
