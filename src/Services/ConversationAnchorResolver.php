<?php

namespace Agentoom\OpenApiV1\Services;

use Agentoom\Core\Models\Agent;
use Agentoom\OpenApiV1\DTOs\ChatCompletionRequest;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Resolves the conversation an OpenAI-compatible request belongs to.
 *
 * The OpenAI chat completions contract carries no conversation identifier: the
 * client resends the whole transcript on every call, so only the client knows
 * which chat a request comes from. Clients that forward that identifier give an
 * exact anchor — OpenWebUI sends `X-OpenWebUI-Chat-Id` (and the user identity
 * headers) when `ENABLE_FORWARD_USER_INFO_HEADERS` is enabled. When no header is
 * present the conversation is fingerprinted from its opening user message, which
 * is stable because clients resend the opening message unchanged on every turn.
 *
 * The result is stored on `agent_conversations.session_id`, namespaced with an
 * `openapi_` prefix so it never collides with browser session ids or the
 * background thread ids (`ai_task_*`, `pipeline_run_*`, `schedule_log_*`,
 * `endpoint_log_*`).
 */
class ConversationAnchorResolver
{
    /**
     * Prefix identifying anchors owned by the OpenAI-compatible API.
     */
    private const PREFIX = 'openapi_';

    /**
     * Maximum length of the sanitized client-supplied conversation id.
     */
    private const MAX_CLIENT_ID_LENGTH = 120;

    /**
     * Length of the opening-message fingerprint digest.
     */
    private const FINGERPRINT_LENGTH = 32;

    /**
     * Resolve the conversation anchor, or null when the conversation cannot be
     * identified and the execution is therefore left untracked.
     */
    public function resolve(Request $request, User $user, Agent $agent, ChatCompletionRequest $completionRequest): ?string
    {
        if (! config('openapi-v1.conversation.track', true)) {
            return null;
        }

        $clientConversationId = $this->clientConversationId($request);

        if ($clientConversationId !== null) {
            return self::PREFIX.$user->id.'_'.$clientConversationId;
        }

        if (! config('openapi-v1.conversation.fingerprint_fallback', true)) {
            return null;
        }

        $openingMessage = $this->openingUserMessage($completionRequest);

        if ($openingMessage === null) {
            return null;
        }

        return self::PREFIX.$user->id.'_fp_'.substr(sha1($agent->id.'|'.$openingMessage), 0, self::FINGERPRINT_LENGTH);
    }

    /**
     * Read the client conversation id from the first configured header carrying one.
     */
    private function clientConversationId(Request $request): ?string
    {
        /** @var array<int, string> $headers */
        $headers = config('openapi-v1.conversation.headers', []);

        foreach ($headers as $header) {
            $value = $request->header($header);

            if (! is_string($value)) {
                continue;
            }

            $sanitized = $this->sanitize($value);

            if ($sanitized !== '') {
                return $sanitized;
            }
        }

        return null;
    }

    /**
     * Reduce a client-supplied id to characters that are safe inside a session id.
     */
    private function sanitize(string $value): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9._-]/', '', trim($value)) ?? '';

        return substr($sanitized, 0, self::MAX_CLIENT_ID_LENGTH);
    }

    /**
     * Get the opening user message of the conversation.
     */
    private function openingUserMessage(ChatCompletionRequest $completionRequest): ?string
    {
        foreach ($completionRequest->messages as $message) {
            $content = trim($message->content);

            if ($message->isRole('user') && $content !== '') {
                return $content;
            }
        }

        return null;
    }
}
