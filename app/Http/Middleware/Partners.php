<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class Partners
{
    public function handle(Request $request, Closure $next): Response
    {
        // Get base URL and token from environment
        $baseUrl = env('PERAHUB_BASE_URL');
        $token = env('PERAHUB_GATEWAY_TOKEN');

        $incomingCode = $request->input('send_partner_code');

        // Concatenate the endpoint to the base URL
        $endpoint = $baseUrl . '/v1/remit/dmt/partner';

        // Call the partner API
       $response = Http::withoutVerifying()
    ->withHeaders([
        'X-Perahub-Gateway-Token' => $token
    ])
    ->get($endpoint);

        if ($response->failed()) {
            Log::error('Failed to fetch partners', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            return response()->json(['error' => 'Unable to fetch partners'], 500);
        }

        $partners = collect($response->json('result'));

        // Find the partner that matches the incoming code
        $partner = $partners->first(fn($p) => ($p['partner_code'] ?? null) === $incomingCode);

        if (!$partner) {
            return response()->json(['error' => 'Invalid partner code'], 403);
        }

        // Attach the partner info to the request
        $request->merge(['partner_info' => $partner]);

        return $next($request);
    }
}
