<?php

namespace Agentoom\OpenApiV1\Tests\Unit;

use Agentoom\Core\Contracts\PrivacyIntegration;
use Agentoom\OpenApiV1\Services\ConversationPrivacyGuard;
use Agentoom\OpenApiV1\Services\StreamingRestorer;
use Agentoom\OpenApiV1\Tests\TestCase;
use Mockery\MockInterface;

class StreamingRestorerTest extends TestCase
{
    /**
     * Placeholder the stubbed privacy service swaps "Ada Lovelace" for.
     */
    private const PLACEHOLDER = '<REDACTED-PERSON-8F13A2C4E1>';

    /**
     * Placeholder the stubbed privacy service swaps "ada@example.com" for.
     */
    private const EMAIL_PLACEHOLDER = '<REDACTED-EMAIL-11C2D3F4A5>';

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(PrivacyIntegration::class, function (MockInterface $mock) {
            $mapping = [
                self::PLACEHOLDER => 'Ada Lovelace',
                self::EMAIL_PLACEHOLDER => 'ada@example.com',
            ];

            $mock->shouldReceive('hasPlaceholders')
                ->andReturnUsing(fn (string $text): bool => str_contains($text, '<REDACTED-'));

            $mock->shouldReceive('restore')
                ->andReturnUsing(fn (string $text): string => str_replace(
                    array_keys($mapping),
                    array_values($mapping),
                    $text,
                ));
        });
    }

    public function test_plain_text_is_forwarded_unchanged(): void
    {
        $restorer = $this->restorer();

        $this->assertSame('Hello there', $restorer->restoreDelta('Hello there'));
        $this->assertSame('', $restorer->finish());
    }

    public function test_placeholder_split_across_deltas_is_never_emitted_partially(): void
    {
        $restorer = $this->restorer();

        $this->assertSame('Sure, ', $restorer->restoreDelta('Sure, <REDACTED-PER'));

        // Nothing restorable is released while the placeholder is incomplete.
        $emitted = 'Sure, ';
        $emitted .= $restorer->restoreDelta('SON-8F13A2C4E1>');
        $emitted .= $restorer->restoreDelta(' is the name.');
        $emitted .= $restorer->finish();

        $this->assertSame('Sure, Ada Lovelace is the name.', $emitted);
    }

    public function test_placeholder_within_a_single_delta_is_restored(): void
    {
        $restorer = $this->restorer();

        $this->assertSame(
            "Contact Ada Lovelace today.\n",
            $restorer->restoreDelta('Contact '.self::PLACEHOLDER." today.\n"),
        );
    }

    public function test_trailing_marker_fragment_is_held_for_one_delta(): void
    {
        $restorer = $this->restorer();

        $this->assertSame('meter is 5 ', $restorer->restoreDelta('meter is 5 <'));
        $this->assertSame('<units', $restorer->restoreDelta('units'));
        $this->assertSame('', $restorer->finish());
    }

    public function test_held_text_is_released_at_the_end_of_the_stream(): void
    {
        $restorer = $this->restorer();

        $emitted = $restorer->restoreDelta('Total <REDACTED-PERS');
        $emitted .= $restorer->restoreDelta('ON-8F13A2C4E1>');
        $emitted .= $restorer->finish();

        $this->assertSame('Total Ada Lovelace', $emitted);
    }

    public function test_unterminated_markup_that_cannot_be_a_placeholder_is_forwarded(): void
    {
        $restorer = $this->restorer();

        // The model stopped mid-html-tag: not a placeholder, so it may flow out.
        $this->assertSame('see <div', $restorer->restoreDelta('see <div'));
    }

    public function test_multiple_placeholders_split_across_many_deltas(): void
    {
        $restorer = $this->restorer();

        $emitted = $restorer->restoreDelta('Names: <REDACTED-PERSON-8F13');
        $emitted .= $restorer->restoreDelta('A2C4E1>, mail <REDACTED-EMAIL-11C2D3F4A5>');
        $emitted .= $restorer->finish();
        $this->assertSame('Names: Ada Lovelace, mail ada@example.com', $emitted);
    }

    private function restorer(): StreamingRestorer
    {
        return new StreamingRestorer(
            new ConversationPrivacyGuard(app(PrivacyIntegration::class)),
            'openapi_1_chat-abc',
        );
    }
}
