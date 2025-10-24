<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\PendingTransaction;
use App\Models\TransactionLog;
use App\Models\SuccessfulTransaction;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Sleep;

class TransactionController extends Controller
{
    //override validation to return consistent JSON structure
    protected function validate(Request $request, array $rules, array $messages = [], array $customAttributes = [])
    {
        $validator = Validator::make($request->all(), $rules, $messages, $customAttributes);

        if ($validator->fails()) {
            throw new HttpResponseException(response()->json([
                'response_code' => 422,
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422));
        }

        return $validator->validated();
    }

    public function inquire(Request $request)
    {
        $this->validate($request, [
            'phrn' => 'required|string',
            'send_partner_code' => 'required|string',
        ]);

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

            TransactionLog::create([
                'type' => 'ACTUAL',
                'endpoint' => '/inquire',
                'request_body' => $payload,
                'response_body' => $responseData,
            ]);

            if ($response->failed()) {
                PendingTransaction::create([
                    'transaction_id' => $payload['phrn'],
                    'request_body' => $payload,
                    'error_message' => $response->body(),
                    'status' => 'failed',
                ]);

                return response()->json([
                    'response_code' => 500,
                    'status' => 'error',
                    'message' => 'Inquiry failed',
                    'errors' => $responseData,
                ], 500);
            }

            return response()->json([
                'response_code' => 200,
                'status' => 'success',
                'message' => 'Inquiry successful',
                'data' => $responseData,
            ], 200);

        } catch (\Exception $e) {
            PendingTransaction::create([
                'transaction_id' => $payload['phrn'],
                'request_body' => $payload,
                'error_message' => $e->getMessage(),
                'status' => 'failed',
            ]);

            return response()->json([
                'response_code' => 500,
                'status' => 'error',
                'message' => 'Server error',
                'errors' => ['exception' => $e->getMessage()],
            ], 500);
        }
    }

    public function getLogs(Request $request)
    {
        $this->validate($request, [
            'type' => 'nullable|in:RAW,ACTUAL',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $query = TransactionLog::query();

        if ($request->filled('type')) $query->where('type', $request->type);
        if ($request->filled('from')) $query->whereDate('created_at', '>=', $request->from);
        if ($request->filled('to')) $query->whereDate('created_at', '<=', $request->to);

        $logs = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'response_code' => 200,
            'status' => 'success',
            'message' => 'Transaction logs fetched',
            'data' => [
                'count' => $logs->count(),
                'logs' => $logs
            ],
        ], 200);
    }

    public function getTransactions(Request $request)
    {
        $this->validate($request, [
            'transaction_id' => 'sometimes|string',
            'partner_id' => 'sometimes|string',
            'from' => 'sometimes|date',
            'to' => 'sometimes|date',
        ]);

        $query = SuccessfulTransaction::query();

        if ($request->filled('transaction_id')) $query->where('transaction_id', $request->transaction_id);
        if ($request->filled('partner_id')) $query->where('partner_id', $request->partner_id);
        if ($request->filled('from')) $query->whereDate('created_at', '>=', $request->from);
        if ($request->filled('to')) $query->whereDate('created_at', '<=', $request->to);

        $transactions = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'response_code' => 200,
            'status' => 'success',
            'message' => 'Transactions fetched',
            'data' => [
                'count' => $transactions->count(),
                'transactions' => $transactions
            ],
        ], 200);
    }

    public function send(Request $request)
    {
        $this->validate($request, [
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
                    'response_code' => 500,
                    'status' => 'error',
                    'message' => 'Send validation failed',
                    'errors' => $validateData
                ], 500);
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
                    'response_code' => 500,
                    'status' => 'error',
                    'message' => 'Send confirm failed',
                    'errors' => $confirmData
                ], 500);
            }

            TransactionLog::create([
                'type' => 'ACTUAL',
                'endpoint' => '/send/confirm',
                'request_body' => ['send_validate_reference_number' => $sendValidateRef],
                'response_body' => $confirmData,
            ]);

            SuccessfulTransaction::create([
                'partner_id' => $payload['send_partner_code'],
                'transaction_id' => $confirmData['result']['phrn'] ?? null,
                'request_body' => $payload,
                'response_body' => $confirmData,
            ]);

            PendingTransaction::where('transaction_id', $payload['partner_reference_number'])->delete();

            return response()->json([
                'response_code' => 200,
                'status' => 'success',
                'message' => 'Send successful',
                'data' => $confirmData['result'],
            ], 200);

        } catch (\Exception $e) {
            PendingTransaction::create([
                'transaction_id' => $payload['partner_reference_number'],
                'partner_id' => $payload['send_partner_code'],
                'request_body' => $payload,
                'error_message' => $e->getMessage(),
                'status' => 'failed',
            ]);

            return response()->json([
                'response_code' => 500,
                'status' => 'error',
                'message' => 'Server error',
                'errors' => ['exception' => $e->getMessage()]
            ], 500);
        }
    }

   public function payout(Request $request)
{
    $this->validate($request, [
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

    // explicit list of keys
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

    $sendTransaction = SuccessfulTransaction::where('transaction_id', $payload['phrn'])->first();

    if (!$sendTransaction) {
        return response()->json([
            'response_code' => 404,
            'status' => 'error',
            'message' => 'Original send transaction not found',
            'errors' => ['phrn' => $payload['phrn']]
        ], 404);
    }

    $payload['payout_partner_code'] = $sendTransaction->partner_id;
    $baseUrl = env('PERAHUB_BASE_URL');
    $token = env('PERAHUB_GATEWAY_TOKEN');

    try {
        // Log RAW request for receive/validate
        TransactionLog::create([
            'type' => 'RAW',
            'endpoint' => '/receive/validate',
            'request_body' => $payload,
        ]);

        $validateResponse = Http::withoutVerifying()
            ->withHeaders([
                'X-Perahub-Gateway-Token' => $token,
                'Accept' => 'application/json',
            ])
            ->post($baseUrl . '/v1/remit/dmt/receive/validate', $payload);

        $validateData = $validateResponse->json() ?: ['raw' => $validateResponse->body()];

        // Log ACTUAL for validate
        TransactionLog::create([
            'type' => 'ACTUAL',
            'endpoint' => '/receive/validate',
            'request_body' => $payload,
            'response_body' => $validateData,
        ]);

        if ($validateResponse->failed() || (($validateData['code'] ?? 0) != 200)) {
            PendingTransaction::create([
                'transaction_id' => $payload['phrn'],
                'partner_id' => $payload['payout_partner_code'],
                'request_body' => $payload,
                'error_message' => $validateResponse->body(),
                'status' => 'failed',
            ]);

            return response()->json([
                'response_code' => $validateResponse->status() ?: 400,
                'status' => 'error',
                'message' => 'Receive validate failed',
                'errors' => $validateData,
            ], $validateResponse->status() ?: 400);
        }

        $payoutValidateRef = data_get($validateData, 'result.payout_validate_reference_number');

        if (!$payoutValidateRef) {
            PendingTransaction::create([
                'transaction_id' => $payload['phrn'],
                'partner_id' => $payload['payout_partner_code'],
                'request_body' => $payload,
                'error_message' => 'Missing payout_validate_reference_number',
                'status' => 'failed',
            ]);

            return response()->json([
                'response_code' => 500,
                'status' => 'error',
                'message' => 'Missing payout_validate_reference_number',
                'errors' => $validateData,
            ], 500);
        }

        // Log RAW request for receive/confirm
        TransactionLog::create([
            'type' => 'RAW',
            'endpoint' => '/receive/confirm',
            'request_body' => ['payout_validate_reference_number' => $payoutValidateRef],
        ]);

        $confirmResponse = Http::withoutVerifying()
            ->withHeaders([
                'X-Perahub-Gateway-Token' => $token,
                'Accept' => 'application/json',
            ])
            ->post($baseUrl . '/v1/remit/dmt/receive/confirm', [
                'payout_validate_reference_number' => $payoutValidateRef,
            ]);

        $confirmData = $confirmResponse->json() ?: ['raw' => $confirmResponse->body()];

        // Log ACTUAL for confirm
        TransactionLog::create([
            'type' => 'ACTUAL',
            'endpoint' => '/receive/confirm',
            'request_body' => ['payout_validate_reference_number' => $payoutValidateRef],
            'response_body' => $confirmData,
        ]);

        if ($confirmResponse->failed() || (($confirmData['code'] ?? 0) != 200)) {
            PendingTransaction::create([
                'transaction_id' => $payload['phrn'],
                'partner_id' => $payload['payout_partner_code'],
                'request_body' => $payload,
                'error_message' => $confirmResponse->body(),
                'status' => 'failed',
            ]);

            return response()->json([
                'response_code' => $confirmResponse->status() ?: 400,
                'status' => 'error',
                'message' => 'Receive confirm failed',
                'errors' => $confirmData,
            ], $confirmResponse->status() ?: 400);
        }

        // SUCCESS: save successful transaction
        SuccessfulTransaction::create([
            'partner_id' => $payload['payout_partner_code'],
            'transaction_id' => $confirmData['result']['phrn'] ?? $payload['phrn'],
            'request_body' => $payload,
            'response_body' => $confirmData,
        ]);

        // Delete any existing pending transaction for this PHRN
        PendingTransaction::where('transaction_id', $payload['phrn'])->delete();

        return response()->json([
            'response_code' => 200,
            'status' => 'success',
            'message' => 'Payout successful',
            'data' => $confirmData['result'],
        ], 200);

    } catch (\Exception $e) {
        // Create a pending transaction record on exception
        PendingTransaction::create([
            'transaction_id' => $payload['phrn'],
            'partner_id' => $payload['payout_partner_code'] ?? null,
            'request_body' => $payload,
            'error_message' => $e->getMessage(),
            'status' => 'failed',
        ]);

        return response()->json([
            'response_code' => 500,
            'status' => 'error',
            'message' => 'Server error',
            'errors' => ['exception' => $e->getMessage()],
        ], 500);
    }
}

}
