<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateWebhookSecret
{
    /**
     * Validasi bahwa request webhook berasal dari Firebase Cloud Functions
     * dengan memeriksa header X-Webhook-Secret.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expectedSecret = config('app.webhook_secret');

        if (!$expectedSecret) {
            // Jika WEBHOOK_SECRET belum di-set, tolak semua request webhook
            return response()->json(['status' => 'error', 'message' => 'Webhook secret not configured.'], 500);
        }

        $providedSecret = $request->header('X-Webhook-Secret');

        if (!$providedSecret || !hash_equals($expectedSecret, $providedSecret)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized webhook request.'], 403);
        }

        return $next($request);
    }
}
