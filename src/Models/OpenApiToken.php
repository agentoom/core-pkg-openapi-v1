<?php

namespace Agentoom\OpenApiV1\Models;

use Agentoom\OpenApiV1\Database\Factories\OpenApiTokenFactory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OpenApiToken extends Model
{
    /** @use HasFactory<OpenApiTokenFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'token',
        'token_prefix',
        'allowed_domains',
        'is_active',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Generate a new plain API key in the OpenAI-compatible format.
     */
    public static function generatePlain(): string
    {
        return 'sk-agt-'.Str::random(40);
    }

    /**
     * Issue a new API token for the given user.
     *
     * @param  string|null  $allowedDomains  Comma or newline separated list of domains/IPs, null to allow all
     * @return array{self, string} The stored token and the plain API key (shown once)
     */
    public static function issue(int $userId, string $name, ?string $allowedDomains, bool $isActive = true): array
    {
        $plain = static::generatePlain();

        $token = static::create([
            'user_id' => $userId,
            'name' => $name,
            'token' => hash('sha256', $plain),
            'token_prefix' => substr($plain, 0, 14),
            'allowed_domains' => $allowedDomains,
            'is_active' => $isActive,
        ]);

        return [$token, $plain];
    }

    /**
     * Replace the stored key with a newly generated one and return the plain value.
     */
    public function regenerate(): string
    {
        $plain = static::generatePlain();

        $this->forceFill([
            'token' => hash('sha256', $plain),
            'token_prefix' => substr($plain, 0, 14),
        ])->save();

        return $plain;
    }

    /**
     * Find an active token by its plain API key.
     */
    public static function findActiveByPlain(string $plain): ?self
    {
        /** @var self|null $token */
        $token = static::query()
            ->where('token', hash('sha256', $plain))
            ->where('is_active', true)
            ->first();

        return $token;
    }

    protected static function newFactory(): OpenApiTokenFactory
    {
        return OpenApiTokenFactory::new();
    }
}
