<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\PendingTransaction;
use App\Models\TransactionLog;

class TransactionController extends Controller
{
    public function inquire(Request $request)
    {
        $request->validate([
            'phrn' => 'required|string',
            'send_partner_code' => 'required|string',
        ]);

        $payload = $request->only(['phrn', 'send_partner_code']);

        $baseUrl = env('PERAHUB_BASE_URL');
        $token = env('PERAHUB_GATEWAY_TOKEN');
        $endpoint = $baseUrl . '/v1/remit/dmt/inquire';

        try {
            // Log RAW request
            TransactionLog::create([
                'type' => 'RAW',
                'endpoint' => '/inquire',
                'request_body' => $payload,
            ]);

            $response = Http::withoutVerifying()
                ->withHeaders([
                    'X-Perahub-Gateway-Token' => $token,
                    'Accept' => 'application/json',
                ])
                ->post($endpoint, $payload);

            $responseData = $response->json();

            if ($response->failed()) {
                PendingTransaction::create([
                    'transaction_id' => $payload['phrn'],
                    'request_body' => $payload,
                    'error_message' => $response->body(),
                    'status' => 'failed',
                ]);

                // Log ACTUAL response
                TransactionLog::create([
                    'type' => 'ACTUAL',
                    'endpoint' => '/inquire',
                    'request_body' => $payload,
                    'response_body' => $responseData,
                ]);

                return response()->json([
                    'error' => 'Inquiry failed',
                    'details' => $responseData,
                ], 500);
            }

            // Log successful ACTUAL response
            TransactionLog::create([
                'type' => 'ACTUAL',
                'endpoint' => '/inquire',
                'request_body' => $payload,
                'response_body' => $responseData,
            ]);

            return response()->json($responseData);
        } catch (\Exception $e) {
            PendingTransaction::create([
                'transaction_id' => $payload['phrn'],
                'request_body' => $payload,
                'error_message' => $e->getMessage(),
                'status' => 'failed',
            ]);

            return response()->json([
                'error' => 'Server error',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function getLogs(Request $request)
{
    // Validate query parameters
    $request->validate([
        'type' => 'nullable|in:RAW,ACTUAL',
        'from' => 'nullable|date',
        'to' => 'nullable|date',
    ]);

    $query = TransactionLog::query();

    // Filter by type (RAW or ACTUAL)
    if ($request->filled('type')) {
        $query->where('type', $request->type);
    }

    // Filter by date range
    if ($request->filled('from')) {
        $query->whereDate('created_at', '>=', $request->from);
    }

    if ($request->filled('to')) {
        $query->whereDate('created_at', '<=', $request->to);
    }

    // Optional: order by newest first
    $logs = $query->orderBy('created_at', 'desc')->get();

    return response()->json([
        'success' => true,
        'count' => $logs->count(),
        'data' => $logs,
    ]);
}
}
