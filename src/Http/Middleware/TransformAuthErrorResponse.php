<?php

namespace Agentoom\OpenApiV1\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Transforms non-OpenAI-compatible 401 responses from ValidateMcpApiToken
 * into OpenAI-compatible error format.
 *
 * This middleware runs AFTER ValidateMcpApiToken in the pipeline.
 * It does NOT duplicate any token parsing, extraction, or comparison logic.
 */
class TransformAuthErrorResponse
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() === Response::HTTP_UNAUTHORIZED) {
            $content = $response->getContent();
            $data = json_decode($content, true);

            if (is_array($data) && isset($data['message'])) {
                return response()->json([
                    'error' => [
                        'message' => $data['message'],
                        'type' => 'authentication_error',
                        'code' => 'invalid_api_key',
                        'param' => null,
                    ],
                ], Response::HTTP_UNAUTHORIZED);
            }
        }

        return $response;
    }
}
