# Agentoom OpenAI API v1 Adapter (`agentoom/core-openapi-v1`)

An OpenAI-compatible REST API gateway and reverse adapter for **Agentoom**.

---

> [!IMPORTANT]
> **Post-Splitting Versioning & Pre-Release Notice (`v0.8.0`)**
> Version `0.8.0` represents the initial standalone release following the modular package extraction and architectural splitting from the Agentoom core monorepo. This package is currently in a pre-release state undergoing integration testing and requires appropriate validation and test coverage across target environments before being designated as production-stable (`1.0.0`).

## 🌟 Overview

The `agentoom/core-openapi-v1` package exposes standard OpenAI v1 endpoints (`/v1/chat/completions`, `/v1/models`) backed by Agentoom's agent network. Any application, SDK, or developer tool designed to talk to OpenAI (such as the official OpenAI Python/Node SDKs, LangChain, LlamaIndex, Cursor, Continue, or LibreChat) can use Agentoom as a direct drop-in replacement by simply altering the `base_url` and `api_key`.

- **Package Name**: `agentoom/core-openapi-v1`
- **Version**: `0.8.0` (Pre-release / Post-split testing)
- **Namespace**: `Agentoom\OpenApiV1\`
- **Service Provider**: `Agentoom\OpenApiV1\OpenApiV1ServiceProvider`

---

## 🚀 Key Features

### 1. OpenAI v1 Compatible Endpoints
- **Chat Completions (`POST /v1/chat/completions`)**: Accepts standard OpenAI chat completion JSON payloads (`messages`, `model`, `temperature`, `stream`, etc.) and executes them via the resolved Agentoom agent.
- **Model Discovery (`GET /v1/models`)**: Lists all available active Agentoom agents mapped as OpenAI model identifiers (e.g. `model: "support-agent"`).

### 2. High-Performance SSE Streaming
- **Server-Sent Events (SSE)**: Full support for `stream: true` streaming responses.
- **Transformers**: Emits OpenAI-compliant `data: {"id": "...", "choices": [{"delta": {"content": "..."}}]}` chunks in real time as tokens arrive (`StreamTransformer`, `StreamingRestorer`).

### 3. Stateful Conversation Anchoring
- **Stateless-to-Stateful Bridge**: OpenAI requests are stateless, but Agentoom agents benefit from stateful memory.
- **Anchor Resolver**: `ConversationAnchorResolver` uses client-provided headers or user identifiers to anchor requests to persistent Agentoom `AgentConversation` threads, allowing agents to retain long-term context across separate API calls.

### 4. Enterprise Security & Token Management
- **API Keys (`OpenApiToken`)**: Bearer token authentication with granular permissions.
- **Agent Scoping**: Restrict specific API tokens to only invoke designated agents.
- **Rate Limiting & Revocation**: Enforce request rate limits and immediately revoke compromised keys.

### 5. Integrated Privacy Protection
- **Privacy Guard (`ConversationPrivacyGuard`)**: Intercepts inbound prompts and outbound responses through Agentoom's privacy pipeline (`agentoom/core-privacy`), stripping sensitive PII before completion.

### 6. Administrative Management
- **Livewire Admin Interface**: Complete dashboard for managing API keys, inspecting call volumes, and viewing client connection logs.
- **Multi-Language Support**: Fully localized in English (`en`), German (`de`), Spanish (`es`), French (`fr`), and Italian (`it`).

---

## 📁 Package Structure

```
core-openapi-v1/
├── config/
│   └── openapi-v1.php              # Route prefix, default settings, rate limits
├── database/
│   ├── factories/                  # OpenApiTokenFactory
│   └── migrations/                 # Table schema for openapi_tokens
├── resources/
│   ├── lang/                       # Translations (de, en, es, fr, it)
│   └── views/livewire/admin/       # Livewire token management views
├── routes/
│   ├── admin.php                   # Token admin routes
│   └── api.php                     # /v1/chat/completions and /v1/models routes
└── src/
    ├── DTOs/                       # ChatCompletionRequest, AgentExecutionInput, Message
    ├── Http/
    │   ├── Controllers/            # ChatCompletionController, ModelController
    │   └── Middleware/             # AuthenticateOpenApiToken
    ├── Models/
    │   └── OpenApiToken.php        # Token model
    ├── Services/                   # DynamicAgentExecutionAdapter, AnchorResolver, PrivacyGuard
    ├── Transformers/               # ChatCompletionTransformer, StreamTransformer
    └── OpenApiV1ServiceProvider.php
```

---

## 💻 Usage Example

Using the official OpenAI Python SDK:

```python
from openai import OpenAI

client = OpenAI(
    base_url="https://your-agentoom-domain.com/v1",
    api_key="agt_live_abcdef1234567890..."
)

response = client.chat.completions.create(
    model="customer-support-agent",
    messages=[
        {"role": "user", "content": "Hello, how can I track my shipment?"}
    ],
    stream=True
)

for chunk in response:
    content = chunk.choices[0].delta.content or ""
    print(content, end="", flush=True)
```
