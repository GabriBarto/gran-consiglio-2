<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ComplaintResource;
use App\Models\Complaint;
use App\Services\Complaints\ComplaintService;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Gestione dei reclami da parte dell'admin (US-S05): esame e decisione (rimborso totale, parziale o rifiuto). */
class ComplaintController extends Controller
{
    /** Di default i reclami da decidere, dal più vecchio; con `status=DECIDED` quelli già decisi, dal più recente. */
    public function index(Request $request): JsonResponse
    {
        $status = $request->validate([
            'status' => ['nullable', Rule::in(['OPEN', 'DECIDED'])],
        ])['status'] ?? 'OPEN';

        $query = Complaint::query()->with(['order.items.box', 'order.seller', 'order.payout', 'order.refunds', 'customer']);
        $query = $status === 'OPEN'
            ? $query->where('status', Complaint::OPEN)->orderBy('id')
            : $query->where('status', '!=', Complaint::OPEN)->orderByDesc('decided_at')->orderByDesc('id');

        return response()->json([
            'open' => Complaint::where('status', Complaint::OPEN)->count(),
            'data' => $query->limit(100)->get()->map(fn (Complaint $c) => ComplaintResource::forAdmin($c))->all(),
        ]);
    }

    public function show(int $complaint): JsonResponse
    {
        return response()->json(ComplaintResource::forAdmin($this->find($complaint)));
    }

    public function decide(Request $request, int $complaint, ComplaintService $complaints): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['ACCEPT_FULL', 'ACCEPT_PARTIAL', 'REJECT'])],
            // Solo per il rimborso parziale: in centesimi
            'refund_cents' => ['required_if:decision,ACCEPT_PARTIAL', 'nullable', 'integer', 'min:1'],
            // Il motivo arriva a cliente e venditore per email
            'note' => ['required', 'string', 'min:5', 'max:1000'],
            // Sospende anche il venditore (US-S05 CA2)
            'suspend_seller' => ['nullable', 'boolean'],
        ]);

        $decided = $complaints->decide($request->user(), $complaint, $data['decision'], $data['refund_cents'] ?? null, trim($data['note']), (bool) ($data['suspend_seller'] ?? false));

        return response()->json(ComplaintResource::forAdmin($decided->fresh()));
    }

    private function find(int $id): Complaint
    {
        return Complaint::find($id) ?? ApiError::throw('NOT_FOUND', 'Reclamo non trovato.', 404);
    }
}
