<?php

namespace Agentoom\OpenApiV1\DTOs;

readonly class Message
{
    /**
     * @param  string  $role  One of: system, user, assistant
     * @param  string  $content  The message content (text or multimodal parts as string)
     */
    public function __construct(
        public string $role,
        public string $content,
    ) {}

    /**
     * Create a Message from an array (typically from JSON request body).
     *
     * @param  array{role: string, content: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            role: $data['role'],
            content: $data['content'],
        );
    }

    /**
     * Check if this message has the given role.
     */
    public function isRole(string $role): bool
    {
        return $this->role === $role;
    }
}
