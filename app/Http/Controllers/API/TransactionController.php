<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use App\Models\PendingTransaction;
use App\Models\TransactionLog;
use App\Models\SuccessfulTransaction;

class TransactionController extends Controller
{
    /**
     * Inquire transaction
     */
    public function inquire(Request $request)
    {
        try {
            $validated = $request->validate([
                'phrn' => 'required|string',
                'send_partner_code' => 'required|string',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'code' => 422,
                'error' => 'Validation failed',
                'details' => $e->errors(),
            ], 422);
        }

        $payload = $request->only(['phrn', 'send_partner_code']);
        $baseUrl = env('PERAHUB_BASE_URL');
        $token = env('PERAHUB_GATEWAY_TOKEN');
        $endpoint = $baseUrl . '/v1/remit/dmt/inquire';

        try {
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

                TransactionLog::create([
                    'type' => 'ACTUAL',
                    'endpoint' => '/inquire',
                    'request_body' => $payload,
                    'response_body' => $responseData,
                ]);

                return response()->json([
                    'code' => 500,
                    'error' => 'Inquiry failed',
                    'details' => $responseData,
                ], 500);
            }

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
                'code' => 500,
                'error' => 'Server error',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get logs
     */
    public function getLogs(Request $request)
    {
        try {
            $request->validate([
                'type' => 'nullable|in:RAW,ACTUAL',
                'from' => 'nullable|date',
                'to' => 'nullable|date',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'code' => 422,
                'error' => 'Validation failed',
                'details' => $e->errors(),
            ], 422);
        }

        $query = TransactionLog::query();

        if ($request->filled('type')) $query->where('type', $request->type);
        if ($request->filled('from')) $query->whereDate('created_at', '>=', $request->from);
        if ($request->filled('to')) $query->whereDate('created_at', '<=', $request->to);

        $logs = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'count' => $logs->count(),
            'data' => $logs,
        ]);
    }

    /**
     * Get successful transactions
     */
    public function getTransactions(Request $request)
    {
        try {
            $request->validate([
                'transaction_id' => 'sometimes|string',
                'partner_id' => 'sometimes|string',
                'from' => 'sometimes|date',
                'to' => 'sometimes|date',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'code' => 422,
                'error' => 'Validation failed',
                'details' => $e->errors(),
            ], 422);
        }

        $query = SuccessfulTransaction::query();

        if ($request->filled('transaction_id')) $query->where('transaction_id', $request->transaction_id);
        if ($request->filled('partner_id')) $query->where('partner_id', $request->partner_id);
        if ($request->filled('from')) $query->whereDate('created_at', '>=', $request->from);
        if ($request->filled('to')) $query->whereDate('created_at', '<=', $request->to);

        $transactions = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'count' => $transactions->count(),
            'transactions' => $transactions,
        ]);
    }

    /**
     * Send transaction
     */
    public function send(Request $request)
    {
        try {
            $validated = $request->validate([
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
        } catch (ValidationException $e) {
            return response()->json([
                'code' => 422,
                'error' => 'Validation failed',
                'details' => $e->errors(),
            ], 422);
        }

        $payload = $request->all();
        $payload['total_amount'] = $payload['principal_amount'] + $payload['service_fee'];
        $baseUrl = env('PERAHUB_BASE_URL');
        $token = env('PERAHUB_GATEWAY_TOKEN');

        try {
            // Log RAW request
            TransactionLog::create([
                'type' => 'RAW',
                'endpoint' => '/send/validate',
                'request_body' => $payload,
            ]);

            $validateResponse = Http::withoutVerifying()
                ->withHeaders([
                    'X-Perahub-Gateway-Token' => $token,
                    'Accept' => 'application/json',
                ])
                ->post($baseUrl . '/v1/remit/dmt/send/validate', $payload);

            $validateData = $validateResponse->json() ?: ['raw' => $validateResponse->body()];

            if ($validateResponse->failed() || ($validateData['code'] ?? 0) != 200) {
                PendingTransaction::create([
                    'transaction_id' => $payload['partner_reference_number'],
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
                    'code' => 400,
                    'error' => 'Send validation failed',
                    'details' => $validateData,
                ], 400);
            }

            TransactionLog::create([
                'type' => 'ACTUAL',
                'endpoint' => '/send/validate',
                'request_body' => $payload,
                'response_body' => $validateData,
            ]);

            $sendValidateRef = $validateData['result']['send_validate_reference_number'];

            $confirmResponse = Http::withoutVerifying()
                ->withHeaders([
                    'X-Perahub-Gateway-Token' => $token,
                    'Accept' => 'application/json',
                ])
                ->post($baseUrl . '/v1/remit/dmt/send/confirm', [
                    'send_validate_reference_number' => $sendValidateRef
                ]);

            $confirmData = $confirmResponse->json() ?: ['raw' => $confirmResponse->body()];

            TransactionLog::create([
                'type' => 'RAW',
                'endpoint' => '/send/confirm',
                'request_body' => ['send_validate_reference_number' => $sendValidateRef],
            ]);

            if ($confirmResponse->failed() || ($confirmData['code'] ?? 0) != 200) {
                PendingTransaction::create([
                    'transaction_id' => $payload['partner_reference_number'],
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
                    'code' => 400,
                    'error' => 'Send confirm failed',
                    'details' => $confirmData,
                ], 400);
            }

            TransactionLog::create([
                'type' => 'ACTUAL',
                'endpoint' => '/send/confirm',
                'request_body' => ['send_validate_reference_number' => $sendValidateRef],
                'response_body' => $confirmData,
            ]);

            // Save successful transaction
            SuccessfulTransaction::create([
                'partner_id' => $payload['send_partner_code'],
                'transaction_id' => $confirmData['result']['phrn'] ?? null,
                'request_body' => $payload,
                'response_body' => $confirmData,
            ]);

            PendingTransaction::where('transaction_id', $payload['partner_reference_number'])->delete();

            return response()->json([
                'message' => 'Send successful',
                'data' => $confirmData['result'],
            ]);

        } catch (\Exception $e) {
            PendingTransaction::create([
                'transaction_id' => $payload['partner_reference_number'],
                'request_body' => $payload,
                'error_message' => $e->getMessage(),
                'status' => 'failed',
            ]);

            return response()->json([
                'code' => 500,
                'error' => 'Server error',
                'details' => $e->getMessage(),
            ], 500);
        }
    }
}
