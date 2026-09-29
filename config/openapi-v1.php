<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OpenAPI v1 Configuration
    |--------------------------------------------------------------------------
    |
    | Authentication is handled by API keys managed in the admin area
    | (Build & Extend → API Keys). Each key is tied to a real user for
    | compliance traceability and may be restricted to specific
    | domains/IPs. The legacy single shared token and its env-var
    | configuration have been removed.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Conversation Tracking
    |--------------------------------------------------------------------------
    |
    | OpenAI-compatible clients resend the full transcript on every request and
    | the OpenAI contract has no conversation identifier, so the conversation a
    | request belongs to is derived from the client (or fingerprinted from the
    | opening message) and stored on agent_conversations.session_id. Tracking
    | makes API traffic visible in the admin (agent traces, chat history, memory
    | analysis) and is what keeps multi-turn chats on a single conversation.
    |
    */
    'conversation' => [

        /*
        | Persist API traffic as conversations. When disabled, requests run
        | exactly as before: stateless, with no conversation rows, messages, or
        | conversation-scoped traces — and no PII anonymization, because the
        | conversation id is the key placeholders are stored and restored under.
        */
        'track' => env('OPENAPI_TRACK_CONVERSATIONS', true),

        /*
        | Request headers carrying the client's conversation identifier, in
        | order of precedence. OpenWebUI sends X-OpenWebUI-Chat-Id together with
        | the user identity headers when ENABLE_FORWARD_USER_INFO_HEADERS is on.
        */
        'headers' => [
            'X-OpenWebUI-Chat-Id',
            'X-Conversation-Id',
            'X-Session-Id',
        ],

        /*
        | When the client sends none of the headers above, fall back to a
        | fingerprint of the conversation's opening user message. The opening
        | message is resent unchanged on every turn, so the anchor stays stable;
        | editing the opening message or reusing it verbatim in a new chat of the
        | same agent by the same user merges the two conversations.
        */
        'fingerprint_fallback' => env('OPENAPI_TRACK_CONVERSATIONS_FINGERPRINT', true),

    ],
];
