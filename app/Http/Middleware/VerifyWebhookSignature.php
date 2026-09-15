<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.partner_webhook.secret');
        $signature = $request->header('X-Webhook-Signature');

        if (! is_string($secret) || $secret === '' || ! is_string($signature)) {
            return response()->json(['message' => 'Assinatura do webhook inválida.'], Response::HTTP_UNAUTHORIZED);
        }

        $expectedSignature = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expectedSignature, $signature)) {
            return response()->json(['message' => 'Assinatura do webhook inválida.'], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
