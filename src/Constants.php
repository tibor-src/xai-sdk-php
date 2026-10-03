<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk;

final class Constants
{
    public const DEFAULT_BASE_URL = 'https://api.x.ai/v1';

    public const DEFAULT_TIMEOUT_MS = 3_600_000;

    public const DEFAULT_IDLE_TIMEOUT_MS = 60_000;

    public const DEFAULT_MAX_RETRIES = 2;

    public const DEFAULT_MAX_RESPONSE_BODY_BYTES = 32 * 1024 * 1024;

    public const DEFAULT_MAX_ERROR_BODY_BYTES = 1024 * 1024;

    public const SDK_USER_AGENT = 'xai-sdk/'.Version::SDK_VERSION.' (php)';

    public const CLIENT_REQUEST_ID_HEADER = 'x-client-request-id';

    public const SDK_STORE_DEFAULT = false;

    public const ENCRYPTED_REASONING = 'reasoning.encrypted_content';

    public const KNOWN_STREAM_EVENT_TYPES = [
        'ping',
        'error',
        'response.created',
        'response.in_progress',
        'response.completed',
        'response.failed',
        'response.incomplete',
        'response.output_item.added',
        'response.output_item.done',
        'response.content_part.added',
        'response.content_part.done',
        'response.output_text.delta',
        'response.output_text.done',
        'response.reasoning_text.delta',
        'response.reasoning_text.done',
        'response.reasoning_summary_text.delta',
        'response.reasoning_summary_text.done',
        'response.function_call_arguments.delta',
        'response.function_call_arguments.done',
        'response.image_generation_call.in_progress',
        'response.image_generation_call.generating',
        'response.image_generation_call.completed',
    ];

    public const SERVER_TOOL_CALL_TYPES = [
        'web_search_call',
        'x_search_call',
        'custom_tool_call',
        'file_search_call',
        'code_interpreter_call',
        'mcp_call',
        'tool_search_call',
        'image_generation_call',
    ];

    public const RETRYABLE_STATUS = [429];

    public const IDEMPOTENT_RETRY_STATUS = [408, 409, 500, 502, 503, 504, 529];

    public const SERVER_ERROR_RETRY_STATUS = [500, 502, 503, 504, 529];

    public const BACKOFF_MS = ['initial' => 250, 'max' => 8_000];

    public const RATE_LIMIT_BACKOFF_MS = ['initial' => 1_000, 'max' => 30_000];

    public const MAX_SSE_EVENT_CHARS = 1_048_576;

    public static function isKnownStreamEventType(string $type): bool
    {
        return in_array($type, self::KNOWN_STREAM_EVENT_TYPES, true);
    }

    public static function isServerToolCallType(mixed $type): bool
    {
        return is_string($type) && in_array($type, self::SERVER_TOOL_CALL_TYPES, true);
    }
}
