<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Errors;

use XaiOfficial\Sdk\Types\HttpMeta;

final class ErrorFactory
{
    private const DROPPED_REASONING_MESSAGE = 'pass response.toInput() (or include encrypted reasoning)';

    public static function rewriteStatusMessage(int $status, string $message): string
    {
        if ($status === 400 && preg_match('/reason(ing)?|encrypted_content|encrypted reasoning/i', $message)) {
            return self::DROPPED_REASONING_MESSAGE;
        }

        return $message;
    }

    public static function requestIds(HttpMeta $http): array
    {
        return [
            'requestId' => $http->requestId,
            'clientRequestId' => $http->clientRequestId,
        ];
    }

    public static function withClientRequestId(\Throwable $err, string $clientRequestId): \Throwable
    {
        if ($err instanceof APIError && $err->clientRequestId === null) {
            return new ($err::class)(
                $err->getMessage(),
                [
                    'requestId' => $err->requestId,
                    'clientRequestId' => $clientRequestId,
                    'status' => $err->status,
                    'code' => $err->errorCode,
                    'param' => $err->param,
                    'type' => $err->type,
                    'body' => $err->body,
                ],
                $err->getPrevious(),
            );
        }

        return $err;
    }

    /**
     * @param array<string, string|string[]> $headers
     */
    public static function requestIdFromHeaders(array $headers): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === 'x-request-id') {
                return is_array($value) ? ($value[0] ?? null) : (string) $value;
            }
        }

        return null;
    }

    public static function errorFromStatus(int $status, string $message, array $init = []): APIError
    {
        $message = self::rewriteStatusMessage($status, $message);

        return match (true) {
            $status === 401 => new AuthenticationError($message, $init),
            $status === 403 => new PermissionDeniedError($message, $init),
            $status === 404 => new NotFoundError($message, $init),
            $status === 429 => new RateLimitError($message, $init),
            $status === 529 => new OverloadedError($message, $init),
            default => new APIStatusError($message, array_merge($init, ['status' => $status])),
        };
    }

    /**
     * @param array<string, mixed>|string|null $body
     */
    public static function errorFromResponse(int $status, ?string $requestId, mixed $body): APIError
    {
        $message = 'API error';
        $init = ['requestId' => $requestId, 'body' => $body];

        if (is_array($body)) {
            if (isset($body['error']) && is_array($body['error'])) {
                $error = $body['error'];
                $message = (string) ($error['message'] ?? $message);
                $init['code'] = $error['code'] ?? null;
                $init['param'] = $error['param'] ?? null;
                $init['type'] = $error['type'] ?? null;
            } elseif (isset($body['message'])) {
                $message = (string) $body['message'];
            }
        }

        return self::errorFromStatus($status, $message, $init);
    }

    public static function errorFromUnknown(\Throwable $err, ?string $requestId): APIError
    {
        if ($err instanceof APIError) {
            return $err;
        }

        return new APIConnectionError($err->getMessage(), ['requestId' => $requestId], $err);
    }

    /**
     * @param array<string, mixed> $event
     */
    public static function streamErrorEvent(array $event, ?string $requestId): APIError
    {
        $message = (string) ($event['message'] ?? 'Stream error');
        $code = isset($event['code']) ? (string) $event['code'] : null;

        return new APIError($message, [
            'requestId' => $requestId,
            'code' => $code,
            'body' => $event,
        ]);
    }
}
