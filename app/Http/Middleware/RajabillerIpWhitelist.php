<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * IP whitelist middleware untuk Rajabiller callback endpoints.
 *
 * Sesuai doktek Rajabiller:
 *   Production : 34.128.119.54 (WAJIB whitelist)
 *   Devel      : 34.128.94.169 (OPTIONAL)
 *
 * Proxy aware — baca X-Forwarded-For dulu (karena hosting di balik Cloudflare/cPanel proxy),
 * fallback ke REMOTE_ADDR kalau header tidak ada.
 */
class RajabillerIpWhitelist
{
    private const PROD_IPS = ['34.128.119.54'];
    private const DEV_IPS  = ['34.128.94.169'];

    public function handle(Request $request, Closure $next): Response
    {
        // Method check — Rajabiller selalu POST
        if ($request->method() !== 'POST') {
            return response()->json([
                'message' => 'Method Not Allowed. Only POST requests are accepted.',
            ], 405);
        }

        $clientIp = $this->getClientIp($request);

        $allowed = app()->environment('production')
            ? self::PROD_IPS
            : array_merge(self::PROD_IPS, self::DEV_IPS);

        if (!in_array($clientIp, $allowed, true)) {
            logger()->warning('Rajabiller callback rejected — IP not whitelisted', [
                'ip'          => $clientIp,
                'forwarded'   => $request->header('X-Forwarded-For'),
                'remote_addr' => $request->server('REMOTE_ADDR'),
                'path'        => $request->path(),
                'payload'     => $request->all(),
            ]);

            return response()->json([
                'message' => 'Forbidden - Invalid IP',
            ], 403);
        }

        // Log incoming callback (untuk debugging)
        logger()->info('Rajabiller callback received', [
            'ip'      => $clientIp,
            'path'    => $request->path(),
            'method'  => $request->input('method'),
            'produk'  => $request->input('produk'),
            'ref1'    => $request->input('ref1'),
            'rc'      => $request->input('rc'),
        ]);

        return $next($request);
    }

    private function getClientIp(Request $request): string
    {
        // X-Forwarded-For bisa berisi multiple IP, IP pertama = client asli
        $forwarded = $request->header('X-Forwarded-For');
        if ($forwarded) {
            return trim(explode(',', $forwarded)[0]);
        }

        return $request->server('REMOTE_ADDR') ?? 'UNKNOWN';
    }
}
