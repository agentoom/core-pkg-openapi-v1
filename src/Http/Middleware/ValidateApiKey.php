<?php

namespace Agentoom\OpenApiV1\Http\Middleware;

use Agentoom\OpenApiV1\Models\OpenApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates OpenAI-compatible API requests via a CRUD-managed API key.
 *
 * The Bearer token must match an active OpenApiToken. The token owner (a real
 * human user) becomes the authenticated identity for the request, so agent
 * visibility filtering and compliance traces attribute to that user.
 *
 * When the token has an allowed domains/IPs list, the request must originate
 * from a listed domain (Origin/Referer host) or IP. An empty list allows all.
 */
class ValidateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();

        if (empty($plain)) {
            return $this->unauthorized('Missing API key. Provide it as a Bearer token.');
        }

        $token = OpenApiToken::findActiveByPlain($plain);

        if ($token === null) {
            return $this->unauthorized('Invalid API key.');
        }

        if (! $this->isAllowed($token, $request)) {
            return response()->json([
                'error' => [
                    'message' => 'This API key is restricted to specific domains or IPs and does not cover this request origin.',
                    'type' => 'authorization_error',
                    'code' => 'access_denied',
                    'param' => null,
                ],
            ], Response::HTTP_FORBIDDEN);
        }

        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        auth()->setUser($token->user);

        return $next($request);
    }

    /**
     * Check the request origin/IP against the token's allowed domains/IPs list.
     *
     * Uses the same semantics as the widget "Allowed Domains or IPs" field:
     * comma or newline separated entries, `*` wildcards, and `*.domain.com`
     * entries also matching the apex domain. Empty list allows all.
     */
    private function isAllowed(OpenApiToken $token, Request $request): bool
    {
        $allowed = array_filter(array_map('trim', preg_split('/[\n,]+/', (string) $token->allowed_domains) ?: []));

        if (empty($allowed)) {
            return true;
        }

        $host = $this->requestHost($request);
        $ip = (string) $request->ip();

        foreach ($allowed as $pattern) {
            if (str_contains($pattern, '://')) {
                $pattern = (string) parse_url($pattern, PHP_URL_HOST) ?: $pattern;
            }

            if ($host !== null && $this->matches($host, $pattern)) {
                return true;
            }

            if ($this->matches($ip, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract the request host from the Origin or Referer header.
     */
    private function requestHost(Request $request): ?string
    {
        foreach ([$request->headers->get('origin'), $request->headers->get('referer')] as $candidate) {
            if (! empty($candidate)) {
                $host = parse_url($candidate, PHP_URL_HOST);

                if (is_string($host) && $host !== '') {
                    return strtolower($host);
                }
            }
        }

        return null;
    }

    /**
     * Match a value against a pattern, supporting `*` wildcards.
     */
    private function matches(string $value, string $pattern): bool
    {
        if ($value === '' || $pattern === '') {
            return false;
        }

        if (strtolower($value) === strtolower($pattern)) {
            return true;
        }

        if (str_contains($pattern, '*')) {
            $regex = str_replace(['.', '*'], ['\.', '.*'], $pattern);

            // If pattern is *.domain.com, it should also match domain.com
            if (str_starts_with($pattern, '*.')) {
                $domainOnly = substr($pattern, 2);

                if (strtolower($value) === strtolower($domainOnly)) {
                    return true;
                }
            }

            return (bool) preg_match('/^'.$regex.'$/i', $value);
        }

        return false;
    }

    /**
     * Return an OpenAI-compatible 401 unauthorized response.
     */
    private function unauthorized(string $message): Response
    {
        return response()->json([
            'error' => [
                'message' => $message,
                'type' => 'authentication_error',
                'code' => 'invalid_api_key',
                'param' => null,
            ],
        ], Response::HTTP_UNAUTHORIZED);
    }
}
