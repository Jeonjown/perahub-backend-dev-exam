<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class Relationship
{
    public function handle(Request $request, Closure $next)
    {
        $incomingRelationship = $request->input('sender_relationship');

        $baseUrl = env('PERAHUB_BASE_URL');
        $token = env('PERAHUB_GATEWAY_TOKEN');
        $endpoint = $baseUrl . '/v1/remit/dmt/relationship';

        $response = Http::withoutVerifying()
            ->withHeaders([
                'X-Perahub-Gateway-Token' => $token,
                'Accept' => 'application/json',
            ])->get($endpoint);

        if ($response->failed()) {
            return response()->json(['error' => 'Unable to fetch relationship data'], 500);
        }

        $relationships = collect($response->json('result'));

        $matchedRelationship = $relationships->first(fn($r) =>
            strtolower($r['relationship']) === strtolower($incomingRelationship)
        );

        if (!$matchedRelationship) {
            return response()->json(['error' => 'Invalid relationship'], 403);
        }

        // Merge object
        $request->merge(['relationship_info' => $matchedRelationship]);

        return $next($request);
    }
}
