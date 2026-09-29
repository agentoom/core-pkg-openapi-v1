<?php

namespace Agentoom\OpenApiV1\Tests\Unit;

use Agentoom\OpenApiV1\DTOs\AgentExecutionInput;
use Agentoom\OpenApiV1\DTOs\Message;
use Agentoom\OpenApiV1\Exceptions\OpenApiException;
use Agentoom\OpenApiV1\Services\MessageToPromptConverter;
use Agentoom\OpenApiV1\Tests\TestCase;

class MessageToPromptConverterTest extends TestCase
{
    private MessageToPromptConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converter = new MessageToPromptConverter;
    }

    public function test_single_user_message_becomes_current_message(): void
    {
        $messages = [
            new Message('user', 'Hello, how are you?'),
        ];

        $result = $this->converter->convert($messages);

        $this->assertInstanceOf(AgentExecutionInput::class, $result);
        $this->assertSame('Hello, how are you?', $result->currentUserMessage);
        $this->assertNull($result->systemInstructions);
        $this->assertNull($result->conversationHistory);
    }

    public function test_system_and_user_messages(): void
    {
        $messages = [
            new Message('system', 'You are a helpful assistant.'),
            new Message('user', 'What is the weather?'),
        ];

        $result = $this->converter->convert($messages);

        $this->assertSame('You are a helpful assistant.', $result->systemInstructions);
        $this->assertSame('What is the weather?', $result->currentUserMessage);
        $this->assertNull($result->conversationHistory);
    }

    public function test_multiple_system_messages_concatenated(): void
    {
        $messages = [
            new Message('system', 'You are helpful.'),
            new Message('system', 'Be concise.'),
            new Message('user', 'Hello.'),
        ];

        $result = $this->converter->convert($messages);

        $this->assertSame("You are helpful.\n\nBe concise.", $result->systemInstructions);
        $this->assertSame('Hello.', $result->currentUserMessage);
    }

    public function test_multi_turn_conversation(): void
    {
        $messages = [
            new Message('user', 'Show me sales.'),
            new Message('assistant', 'The sales were $10,000.'),
            new Message('user', 'What about Germany?'),
        ];

        $result = $this->converter->convert($messages);

        $this->assertSame('What about Germany?', $result->currentUserMessage);
        $this->assertStringContainsString('[User]', $result->conversationHistory);
        $this->assertStringContainsString('Show me sales.', $result->conversationHistory);
        $this->assertStringContainsString('[Assistant]', $result->conversationHistory);
        $this->assertStringContainsString('The sales were $10,000.', $result->conversationHistory);
    }

    public function test_system_with_multi_turn_conversation(): void
    {
        $messages = [
            new Message('system', 'You are an ERP assistant.'),
            new Message('user', 'Show me sales.'),
            new Message('assistant', 'Sales were $10k.'),
            new Message('user', 'What about Germany?'),
        ];

        $result = $this->converter->convert($messages);

        $this->assertSame('You are an ERP assistant.', $result->systemInstructions);
        $this->assertSame('What about Germany?', $result->currentUserMessage);
        $this->assertNotNull($result->conversationHistory);
    }

    public function test_prompt_builds_correctly(): void
    {
        $messages = [
            new Message('user', 'Show me sales.'),
            new Message('assistant', 'Sales were $10k.'),
            new Message('user', 'What about Germany?'),
        ];

        $result = $this->converter->convert($messages);
        $prompt = $result->toPrompt();

        $this->assertStringContainsString('Show me sales.', $prompt);
        $this->assertStringContainsString('Sales were $10k.', $prompt);
        $this->assertStringContainsString('What about Germany?', $prompt);
        // Current message should come last
        $this->assertStringEndsWith('What about Germany?', $prompt);
    }

    public function test_throws_on_empty_messages(): void
    {
        $this->expectException(OpenApiException::class);
        $this->expectExceptionMessage('The "messages" field is required.');

        $this->converter->convert([]);
    }

    public function test_throws_when_last_message_is_assistant(): void
    {
        $messages = [
            new Message('user', 'Hello.'),
            new Message('assistant', 'Hi there.'),
        ];

        $this->expectException(OpenApiException::class);
        $this->expectExceptionMessage('The last message must have the role "user".');

        $this->converter->convert($messages);
    }

    public function test_throws_when_only_system_messages(): void
    {
        $messages = [
            new Message('system', 'You are helpful.'),
        ];

        $this->expectException(OpenApiException::class);
        $this->expectExceptionMessage('The last message must have the role "user".');

        $this->converter->convert($messages);
    }
}
