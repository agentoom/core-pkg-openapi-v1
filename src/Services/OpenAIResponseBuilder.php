<?php

namespace Agentoom\OpenApiV1\Services;

use Laravel\Ai\Responses\Data\Usage;

class OpenAIResponseBuilder
{
    /**
     * Generate an OpenAI-compatible chat completion ID.
     *
     * Format: chatcmpl-{uuid7}
     */
    public function generateId(): string
    {
        return 'chatcmpl-'.self::uuid7();
    }

    /**
     * Get the current Unix timestamp.
     */
    public function now(): int
    {
        return time();
    }

    /**
     * Map Laravel AI SDK Usage to OpenAI-compatible usage array.
     *
     * Returns null if no usage data is available.
     *
     * @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int}|null
     */
    public function mapUsage(?Usage $usage): ?array
    {
        if ($usage === null) {
            return null;
        }

        return [
            'prompt_tokens' => $usage->promptTokens,
            'completion_tokens' => $usage->completionTokens,
            'total_tokens' => $usage->promptTokens + $usage->completionTokens,
        ];
    }

    /**
     * Generate a UUID v7-compatible ID.
     *
     * UUID v7 is time-ordered (Unix timestamp in milliseconds + random bits).
     */
    private static function uuid7(): string
    {
        $unixTsMs = (int) (microtime(true) * 1000);

        // 16 random bytes
        $bytes = random_bytes(10);

        // Format: time_low-time_mid-time_hi_and_version-clock_seq_and_reserved-clock_seq_low-node
        return sprintf(
            '%08x-%04x-%04x-%02x%02x-%04x%08x',
            // time_low (32 bits)
            ($unixTsMs & 0x00000000FFFFFFFF),
            // time_mid (16 bits)
            ($unixTsMs >> 32) & 0xFFFF,
            // time_hi_and_version (12 bits + 4 version bits): version 7 = 0x7000
            (int) ((($unixTsMs & 0x0FFF000000000000) >> 48) | 0x7000),
            // clock_seq_hi_and_reserved (6 bits + 2 variant bits): variant 10xx
            (ord($bytes[0]) & 0x3F) | 0x80,
            // clock_seq_low (8 bits)
            ord($bytes[1]),
            // node_hi (16 bits)
            (ord($bytes[2]) << 8) | ord($bytes[3]),
            // node_low (32 bits)
            (ord($bytes[4]) << 24) | (ord($bytes[5]) << 16) | (ord($bytes[6]) << 8) | ord($bytes[7]),
        );
    }

    /**
     * Convert a byte string to an integer.
     */
    private static function bytesToInt(string $bytes): int
    {
        $result = 0;
        $length = strlen($bytes);
        for ($i = 0; $i < $length; $i++) {
            $result = ($result << 8) | ord($bytes[$i]);
        }

        return $result;
    }
}
