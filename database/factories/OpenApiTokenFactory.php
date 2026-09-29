<?php

namespace Agentoom\OpenApiV1\Database\Factories;

use Agentoom\OpenApiV1\Models\OpenApiToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OpenApiToken>
 */
class OpenApiTokenFactory extends Factory
{
    protected $model = OpenApiToken::class;

    public function definition(): array
    {
        $plain = OpenApiToken::generatePlain();

        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'token' => hash('sha256', $plain),
            'token_prefix' => substr($plain, 0, 14),
            'allowed_domains' => null,
            'is_active' => true,
        ];
    }
}
