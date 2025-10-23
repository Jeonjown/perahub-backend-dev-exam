<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class Occupation
{
    public function handle(Request $request, Closure $next): Response
    {
        // Get base URL and token from environment
        $baseUrl = env('PERAHUB_BASE_URL');
        $token = env('PERAHUB_GATEWAY_TOKEN');

        $incomingOccupation = $request->input('sender_occupation');

        // Concatenate the endpoint
        $endpoint = $baseUrl . '/v1/remit/dmt/occupation';

        // Call the occupation API
        $response = Http::withoutVerifying()
            ->withHeaders([
                'X-Perahub-Gateway-Token' => $token
            ])
            ->get($endpoint);

        if ($response->failed()) {
            Log::error('Failed to fetch occupations', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            return response()->json(['error' => 'Unable to fetch occupations'], 500);
        }

        $occupations = collect($response->json('result'));


        // Find the matching occupation
        $matchedOccupation = $occupations->first(fn($o) => ($o['occupation'] ?? null) === $incomingOccupation);

        if (!$matchedOccupation) {
            return response()->json(['error' => 'Invalid occupation'], 403);
        }

        $request->merge(['occupation_info' => $matchedOccupation]);

        return $next($request);
    }
}
