<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proteksi opsional untuk endpoint scan.
 *
 * Jika SCAN_API_KEY tidak di-set (default lokal), endpoint terbuka.
 * Jika di-set, request wajib membawa:
 *   - header "Authorization: Bearer <key>", atau
 *   - header "X-Api-Key: <key>"
 */
class VerifyScanApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('scanner.api_key');

        if ($expected === '') {
            return $next($request);
        }

        $provided = $request->bearerToken() ?? (string) $request->header('X-Api-Key', '');

        if ($provided !== '' && hash_equals($expected, $provided)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'API key tidak valid atau tidak disertakan. Kirim header "Authorization: Bearer <key>" atau "X-Api-Key".',
        ], 401);
    }
}
