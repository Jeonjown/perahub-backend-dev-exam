<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class Purpose
{
    public function handle(Request $request, Closure $next): Response
    {
        // Get base URL and token from environment
        $baseUrl = env('PERAHUB_BASE_URL');
        $token = env('PERAHUB_GATEWAY_TOKEN');

        $incomingPurpose = $request->input('sender_purpose');

        // Concatenate the endpoint to the base URL
        $endpoint = $baseUrl . '/v1/remit/dmt/purpose';

        // Call the purpose API
        $response = Http::withoutVerifying()
            ->withHeaders([
                'X-Perahub-Gateway-Token' => $token
            ])
            ->get($endpoint);

        if ($response->failed()) {
            Log::error('Failed to fetch purposes', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            return response()->json(['error' => 'Unable to fetch purposes'], 500);
        }

        $purposes = collect($response->json('result'));
        Log::info('Purposes fetched:', $purposes->toArray());
        Log::info('Incoming purpose:', [$incomingPurpose]);

        // Find the matching purpose
        $matchedPurpose = $purposes->first(fn($p) => ($p['purpose_of_remittance'] ?? null) === $incomingPurpose);

        if (!$matchedPurpose) {
            return response()->json(['error' => 'Invalid purpose'], 403);
        }

        // Attach matched purpose info to the request
        $request->merge(['purpose_info' => $matchedPurpose]);

        return $next($request);
    }
}
