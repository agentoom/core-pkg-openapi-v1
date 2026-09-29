<?php

namespace Agentoom\OpenApiV1\Exceptions;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class OpenApiException extends RuntimeException
{
    /**
     * @param  string  $message  Human-readable error description
     * @param  string  $type  One of: invalid_request_error, authentication_error, server_error
     * @param  string  $errorCode  Machine-readable error code
     * @param  string|null  $param  The parameter that caused the error, if applicable
     * @param  int  $httpStatus  HTTP status code
     */
    public function __construct(
        string $message,
        public readonly string $type,
        public readonly string $errorCode,
        public readonly ?string $param = null,
        public readonly int $httpStatus = Response::HTTP_BAD_REQUEST,
    ) {
        parent::__construct($message);
    }

    /**
     * Convert the exception to an OpenAI-compatible error array.
     *
     * @return array{error: array{message: string, type: string, code: string, param: string|null}}
     */
    public function toArray(): array
    {
        return [
            'error' => [
                'message' => $this->getMessage(),
                'type' => $this->type,
                'code' => $this->errorCode,
                'param' => $this->param,
            ],
        ];
    }

    /**
     * Create an exception for a missing "model" field.
     */
    public static function missingModel(): self
    {
        return new self(
            'The "model" field is required.',
            'invalid_request_error',
            'invalid_request',
            'model',
            Response::HTTP_BAD_REQUEST
        );
    }

    /**
     * Create an exception for a missing "messages" field.
     */
    public static function missingMessages(): self
    {
        return new self(
            'The "messages" field is required.',
            'invalid_request_error',
            'invalid_request',
            'messages',
            Response::HTTP_BAD_REQUEST
        );
    }

    /**
     * Create an exception for invalid message role.
     */
    public static function invalidMessageRole(string $role): self
    {
        return new self(
            "Invalid message role: \"{$role}\". Supported roles: system, user, assistant.",
            'invalid_request_error',
            'invalid_message_role',
            'messages',
            Response::HTTP_BAD_REQUEST
        );
    }

    /**
     * Create an exception for an empty message content.
     */
    public static function emptyMessageContent(): self
    {
        return new self(
            'Message content cannot be empty.',
            'invalid_request_error',
            'empty_message',
            'messages',
            Response::HTTP_BAD_REQUEST
        );
    }

    /**
     * Create an exception when the last message is not from the user.
     */
    public static function invalidMessageOrder(): self
    {
        return new self(
            'The last message must have the role "user".',
            'invalid_request_error',
            'invalid_message_order',
            'messages',
            Response::HTTP_BAD_REQUEST
        );
    }

    /**
     * Create an exception for a model not found / unauthorized.
     */
    public static function modelNotFound(string $model): self
    {
        return new self(
            "The model \"{$model}\" does not exist or you do not have access to it.",
            'invalid_request_error',
            'model_not_found',
            'model',
            Response::HTTP_NOT_FOUND
        );
    }

    /**
     * Create an exception for agent execution failure.
     */
    public static function agentExecutionFailed(string $message = 'Agent execution failed.'): self
    {
        return new self(
            $message,
            'server_error',
            'agent_execution_failed',
            null,
            Response::HTTP_INTERNAL_SERVER_ERROR
        );
    }

    /**
     * Create an exception for an invalid JSON request body.
     */
    public static function invalidJson(): self
    {
        return new self(
            'Invalid JSON in request body.',
            'invalid_request_error',
            'invalid_request',
            null,
            Response::HTTP_BAD_REQUEST
        );
    }
}
