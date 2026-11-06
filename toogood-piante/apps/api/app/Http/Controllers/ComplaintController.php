<?php

namespace App\Http\Controllers;

use App\Http\Resources\ComplaintResource;
use App\Models\Complaint;
use App\Services\Complaints\ComplaintService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Reclamo del cliente su un proprio ordine (US-C14). Solo testo, nei quattro casi ammessi e entro il termine. */
class ComplaintController extends Controller
{
    public function store(Request $request, int $order, ComplaintService $complaints): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(Complaint::REASONS)],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $complaint = $complaints->open($request->user(), $order, $data['reason'], trim($data['description']));

        return response()->json(ComplaintResource::forCustomer($complaint), 201);
    }
}
