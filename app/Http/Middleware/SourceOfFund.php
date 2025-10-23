<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class SourceOfFund
{
    public function handle(Request $request, Closure $next): Response
    {
        $baseUrl = env('PERAHUB_BASE_URL');
        $token = env('PERAHUB_GATEWAY_TOKEN');

        $incomingSource = $request->input('sender_source_of_fund');
        $endpoint = $baseUrl . '/v1/remit/dmt/sourcefund';

        $response = Http::withoutVerifying()
            ->withHeaders([
                'X-Perahub-Gateway-Token' => $token
            ])
            ->get($endpoint);

        if ($response->failed()) {
            return response()->json(['error' => 'Unable to fetch sources of fund'], 500);
        }

        $sources = collect($response->json('result'));
        $matchedSource = $sources->first(fn($s) => ($s['source_of_fund'] ?? null) === $incomingSource);

        if (!$matchedSource) {
            return response()->json(['error' => 'Invalid source of fund'], 403);
        }

        $request->merge(['source_of_fund_info' => $matchedSource]);

        return $next($request);
    }
}
