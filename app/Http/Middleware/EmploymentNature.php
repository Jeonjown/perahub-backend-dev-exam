<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class EmploymentNature
{
    public function handle(Request $request, Closure $next): Response
    {
        $baseUrl = env('PERAHUB_BASE_URL');
        $token = env('PERAHUB_GATEWAY_TOKEN');

        $incomingNature = $request->input('sender_employment_nature');
        $endpoint = $baseUrl . '/v1/remit/dmt/employment';

        $response = Http::withoutVerifying()
            ->withHeaders([
                'X-Perahub-Gateway-Token' => $token
            ])
            ->get($endpoint);

        if ($response->failed()) {
            return response()->json(['error' => 'Unable to fetch employment natures'], 500);
        }

        $natures = collect($response->json('result'));
        $matchedNature = $natures->first(fn($n) => ($n['employment_nature'] ?? null) === $incomingNature);

        if (!$matchedNature) {
            return response()->json(['error' => 'Invalid employment nature'], 403);
        }

        $request->merge(['employment_nature_info' => $matchedNature]);

        return $next($request);
    }
}
