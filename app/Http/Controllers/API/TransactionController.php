<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\PendingTransaction;
use App\Models\TransactionLog;
use Illuminate\Validation\ValidationException;
use App\Models\SuccessfulTransaction;
use Illuminate\Support\Facades\Log;

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

        // order by newest first
        $logs = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'count' => $logs->count(),
            'data' => $logs,
        ]);
    }

    public function getTransactions(Request $request)
    {
        // Validate query parameters
        $request->validate([
            'transaction_id' => 'sometimes|string',
            'partner_id' => 'sometimes|string',
            'from' => 'sometimes|date',
            'to' => 'sometimes|date',
        ]);

        // Start building query
        $query = \App\Models\SuccessfulTransaction::query();

        // Filter by transaction_id
        if ($request->filled('transaction_id')) {
            $query->where('transaction_id', $request->transaction_id);
        }

        // Filter by partner_id
        if ($request->filled('partner_id')) {
            $query->where('partner_id', $request->partner_id);
        }

        // Filter by date range
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        // Get transactions ordered by latest first
        $transactions = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'count' => $transactions->count(),
            'transactions' => $transactions
        ]);
    }

    public function send(Request $request)
{
    // 1️⃣ Validate request
    $request->validate([
        'partner_reference_number' => 'required|string',
        'principal_amount' => 'required|numeric',
        'service_fee' => 'required|numeric',
        'iso_currency' => 'required|string',
        'conversion_rate' => 'required|numeric',
        'iso_originating_country' => 'required|string',
        'iso_destination_country' => 'required|string',
        'sender_last_name' => 'required|string',
        'sender_first_name' => 'required|string',
        'receiver_last_name' => 'required|string',
        'receiver_first_name' => 'required|string',
        'sender_birth_date' => 'required|date',
        'sender_relationship' => 'required|string',
        'sender_purpose' => 'required|string',
        'sender_source_of_fund' => 'required|string',
        'sender_occupation' => 'required|string',
        'sender_employment_nature' => 'required|string',
        'send_partner_code' => 'required|string',
    ]);

    $payload = $request->all();
    $payload['total_amount'] = $payload['principal_amount'] + $payload['service_fee'];

    $baseUrl = env('PERAHUB_BASE_URL');
    $token = env('PERAHUB_GATEWAY_TOKEN');

    try {
        // Log RAW request for /send/validate
        TransactionLog::create([
            'type' => 'RAW',
            'endpoint' => '/send/validate',
            'request_body' => $payload,
        ]);

        // Call /send/validate API
        $validateResponse = Http::withoutVerifying()
            ->withHeaders([
                'X-Perahub-Gateway-Token' => $token,
                'Accept' => 'application/json',
            ])
            ->post($baseUrl . '/v1/remit/dmt/send/validate', $payload);

        $validateData = $validateResponse->json() ?: ['raw' => $validateResponse->body()];

        // Failed validation
        if ($validateResponse->failed() || ($validateData['code'] ?? 0) != 200) {
            PendingTransaction::create([
                'transaction_id' => $payload['partner_reference_number'],
                'partner_id' => $payload['send_partner_code'],
                'request_body' => $payload,
                'error_message' => $validateResponse->body(),
                'status' => 'failed',
            ]);

            TransactionLog::create([
                'type' => 'ACTUAL',
                'endpoint' => '/send/validate',
                'request_body' => $payload,
                'response_body' => $validateData,
            ]);

            return response()->json([
                'error' => 'Send validation failed',
                'details' => $validateData
            ], 500);
        }

        // Log successful validation
        TransactionLog::create([
            'type' => 'ACTUAL',
            'endpoint' => '/send/validate',
            'request_body' => $payload,
            'response_body' => $validateData,
        ]);

        $sendValidateRef = $validateData['result']['send_validate_reference_number'];

        // Call /send/confirm API
        $confirmResponse = Http::withoutVerifying()
            ->withHeaders([
                'X-Perahub-Gateway-Token' => $token,
                'Accept' => 'application/json',
            ])
            ->post($baseUrl . '/v1/remit/dmt/send/confirm', [
                'send_validate_reference_number' => $sendValidateRef
            ]);

        $confirmData = $confirmResponse->json() ?: ['raw' => $confirmResponse->body()];

        // Log RAW confirm request
        TransactionLog::create([
            'type' => 'RAW',
            'endpoint' => '/send/confirm',
            'request_body' => ['send_validate_reference_number' => $sendValidateRef],
        ]);

        // Failed confirmation
        if ($confirmResponse->failed() || ($confirmData['code'] ?? 0) != 200) {
            PendingTransaction::create([
                'transaction_id' => $payload['partner_reference_number'],
                'partner_id' => $payload['send_partner_code'],
                'request_body' => $payload,
                'error_message' => $confirmResponse->body(),
                'status' => 'failed',
            ]);

            TransactionLog::create([
                'type' => 'ACTUAL',
                'endpoint' => '/send/confirm',
                'request_body' => ['send_validate_reference_number' => $sendValidateRef],
                'response_body' => $confirmData,
            ]);

            return response()->json([
                'error' => 'Send confirm failed',
                'details' => $confirmData
            ], 500);
        }

        // Log successful ACTUAL confirm response
        TransactionLog::create([
            'type' => 'ACTUAL',
            'endpoint' => '/send/confirm',
            'request_body' => ['send_validate_reference_number' => $sendValidateRef],
            'response_body' => $confirmData,
        ]);

        // Save successful transaction
        \App\Models\SuccessfulTransaction::create([
            'partner_id' => $payload['send_partner_code'],
            'transaction_id' => $confirmData['result']['phrn'] ?? null,
            'request_body' => $payload,
            'response_body' => $confirmData,
        ]);

        // Remove from pending if exists
        PendingTransaction::where('transaction_id', $payload['partner_reference_number'])->delete();

        return response()->json([
            'message' => 'Send successful',
            'data' => $confirmData['result'],
        ]);

    } catch (\Exception $e) {
        PendingTransaction::create([
            'transaction_id' => $payload['partner_reference_number'],
            'partner_id' => $payload['send_partner_code'],
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



public function payout(Request $request)
{
    // 🔹 Log raw incoming request
    Log::info('Incoming payout request', $request->all());

    $request->validate([
        'phrn' => 'required|string',
        'principal_amount' => 'required|numeric',
        'iso_originating_country' => 'required|string',
        'iso_destination_country' => 'required|string',
        'sender_last_name' => 'required|string',
        'sender_first_name' => 'required|string',
        'sender_middle_name' => 'required|string',
        'receiver_last_name' => 'required|string',
        'receiver_first_name' => 'required|string',
        'receiver_middle_name' => 'required|string',
    ]);

    $payload = $request->only([
        'phrn',
        'principal_amount',
        'iso_originating_country',
        'iso_destination_country',
        'sender_last_name',
        'sender_first_name',
        'sender_middle_name',
        'receiver_last_name',
        'receiver_first_name',
        'receiver_middle_name',
    ]);

    // 🔹 Fetch original send transaction
    $sendTransaction = \App\Models\SuccessfulTransaction::where('transaction_id', $payload['phrn'])->first();

    if (!$sendTransaction) {
        Log::warning('Original send transaction not found', ['phrn' => $payload['phrn']]);
        return response()->json([
            'error' => 'Original send transaction not found',
            'phrn' => $payload['phrn']
        ], 404);
    }

    $payload['payout_partner_code'] = $sendTransaction->partner_id;

    // 🔹 Log payload before sending to API
    Log::info('Payout payload prepared', $payload);

    $baseUrl = env('PERAHUB_BASE_URL');
    $token = env('PERAHUB_GATEWAY_TOKEN');

    try {
        // 1️⃣ Call Receive Validate
        Log::info('Calling receive/validate', ['payload' => $payload]);

        $validateResponse = Http::withoutVerifying()
            ->withHeaders([
                'X-Perahub-Gateway-Token' => $token,
                'Accept' => 'application/json',
            ])
            ->post($baseUrl . '/v1/remit/dmt/receive/validate', $payload);

        $validateData = $validateResponse->json() ?: ['raw' => $validateResponse->body()];

        Log::info('Receive Validate response', $validateData);

        if ($validateResponse->failed() || (($validateData['code'] ?? 0) != 200)) {
            Log::error('Receive validate failed', ['response' => $validateData]);
            return response()->json([
                'error' => 'Receive validate failed',
                'details' => $validateData,
            ], $validateResponse->status() ?: 400);
        }

        $payoutValidateRef = data_get($validateData, 'result.payout_validate_reference_number');

        if (!$payoutValidateRef) {
            Log::error('Missing payout_validate_reference_number', ['validateData' => $validateData]);
            return response()->json([
                'error' => 'Missing payout_validate_reference_number in validate response',
                'details' => $validateData,
            ], 500);
        }

        // 2️⃣ Call Receive Confirm
        Log::info('Calling receive/confirm', ['payout_validate_reference_number' => $payoutValidateRef]);

        $confirmResponse = Http::withoutVerifying()
            ->withHeaders([
                'X-Perahub-Gateway-Token' => $token,
                'Accept' => 'application/json',
            ])
            ->post($baseUrl . '/v1/remit/dmt/receive/confirm', [
                'payout_validate_reference_number' => $payoutValidateRef,
            ]);

        $confirmData = $confirmResponse->json() ?: ['raw' => $confirmResponse->body()];

        Log::info('Receive Confirm response', $confirmData);

        if ($confirmResponse->failed() || (($confirmData['code'] ?? 0) != 200)) {
            Log::error('Receive confirm failed', ['response' => $confirmData]);
            return response()->json([
                'error' => 'Receive confirm failed',
                'details' => $confirmData,
            ], $confirmResponse->status() ?: 400);
        }

        // 3️⃣ Save successful payout
        \App\Models\SuccessfulTransaction::create([
            'partner_id' => $payload['payout_partner_code'],
            'transaction_id' => $confirmData['result']['phrn'] ?? $payload['phrn'],
            'request_body' => $payload,
            'response_body' => $confirmData,
        ]);

        Log::info('Payout successful', ['result' => $confirmData['result']]);

        return response()->json([
            'code' => 200,
            'message' => 'Payout successful',
            'result' => $confirmData['result'],
        ]);

    } catch (\Exception $e) {
        Log::error('Payout exception', ['message' => $e->getMessage(), 'payload' => $payload]);

        return response()->json([
            'code' => 500,
            'error' => 'Server error',
            'details' => $e->getMessage(),
        ], 500);
    }
}





}
