<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp;

use TiborSrc\XaiSdkPhp\Http\AbortSignal;
use TiborSrc\XaiSdkPhp\Http\HeaderBag;

const DROPPED_REASONING_MESSAGE = 'pass response.toInput() (or include encrypted reasoning)';

function rewriteStatusMessage(int $status, string $message): string
{
    if ($status === 400 && preg_match('/reason(ing)?|encrypted_content|encrypted reasoning/i', $message) === 1) {
        return DROPPED_REASONING_MESSAGE;
    }

    return $message;
}

function requestIdFromHeaders(HeaderBag $headers): ?string
{
    return $headers->get('x-request-id');
}

/** @return array{requestId: string|null, clientRequestId: string} */
function requestIds(HttpMeta $http): array
{
    return ['requestId' => $http->requestId, 'clientRequestId' => $http->clientRequestId];
}

function withClientRequestId(mixed $err, string $clientRequestId): mixed
{
    if ($err instanceof APIError && $err->clientRequestId === null) {
        $err->clientRequestId = $clientRequestId;
    }

    return $err;
}

/** @param array<string, mixed> $init */
function errorFromStatus(int $status, string $message, array $init = []): APIStatusError
{
    $rewritten = rewriteStatusMessage($status, $message);
    $base = $init;
    $base['status'] = $status;

    return match ($status) {
        401 => new AuthenticationError($rewritten, $base),
        403 => new PermissionDeniedError($rewritten, $base),
        404 => new NotFoundError($rewritten, $base),
        429 => new RateLimitError($rewritten, $base),
        529 => new OverloadedError($rewritten, $base),
        default => new APIStatusError($rewritten, $base),
    };
}

function errorFromResponse(int $status, string $statusText, HeaderBag $headers, mixed $body = null): APIStatusError
{
    $requestId = requestIdFromHeaders($headers);
    $parsed = $body;
    $message = $statusText !== '' ? $statusText : 'HTTP ' . $status;
    $code = null;
    $param = null;
    $type = null;
    if (is_record($parsed)) {
        $err = isset($parsed['error']) && is_record($parsed['error']) ? $parsed['error'] : $parsed;
        if (isset($err['message']) && is_string($err['message'])) {
            $message = $err['message'];
        } elseif (isset($parsed['error']) && is_string($parsed['error'])) {
            $message = $parsed['error'];
        }
        if (isset($err['code']) && is_string($err['code'])) {
            $code = $err['code'];
        } elseif (isset($err['code']) && is_int($err['code'])) {
            $code = (string) $err['code'];
        } elseif (isset($err['code']) && is_float($err['code'])) {
            $code = (string) $err['code'];
        }
        if (isset($err['param']) && is_string($err['param'])) {
            $param = $err['param'];
        }
        if (isset($err['type']) && is_string($err['type'])) {
            $type = $err['type'];
        }
    } elseif (is_string($parsed) && $parsed !== '') {
        $message = $parsed;
    }

    return errorFromStatus($status, $message, [
        'requestId' => $requestId,
        'code' => $code,
        'param' => $param,
        'type' => $type,
        'body' => $parsed,
    ]);
}

function errorFromAbort(AbortSignal $signal, ?string $requestId): APIError
{
    $reason = $signal->activeReason();
    if ($reason instanceof APIError) {
        if ($reason->name === 'TimeoutError' && $reason->requestId === null && $requestId !== null) {
            return new TimeoutError($reason->getMessage(), ['requestId' => $requestId, 'cause' => $reason]);
        }

        return $reason;
    }
    if (isTimeoutLike($reason)) {
        return new TimeoutError('Request timed out', ['requestId' => $requestId, 'cause' => $reason]);
    }
    $message = 'Request aborted';
    if (is_string($reason) && $reason !== '') {
        $message = $reason;
    } elseif ($reason instanceof \Throwable) {
        $message = $reason->getMessage();
    }

    return new AbortError($message, ['requestId' => $requestId, 'cause' => $reason]);
}

function errorFromUnknown(mixed $err, ?string $requestId): APIError
{
    if ($err instanceof APIError) {
        return $err;
    }
    if (isTimeoutLike($err)) {
        return new TimeoutError($err instanceof \Throwable ? $err->getMessage() : 'Request timed out', [
            'requestId' => $requestId,
            'cause' => $err,
        ]);
    }
    if (isAbortLike($err)) {
        return new AbortError($err instanceof \Throwable ? $err->getMessage() : 'Request aborted', [
            'requestId' => $requestId,
            'cause' => $err,
        ]);
    }
    $message = $err instanceof \Throwable ? $err->getMessage() : 'Connection error';

    return new APIConnectionError($message, ['requestId' => $requestId, 'cause' => $err]);
}

function isTimeoutLike(mixed $err): bool
{
    if ($err instanceof APIError && $err->name === 'TimeoutError') {
        return true;
    }

    return is_object($err) && property_exists($err, 'name') && $err->name === 'TimeoutError';
}

function isAbortLike(mixed $err): bool
{
    if ($err instanceof APIError && $err->isAbort()) {
        return true;
    }

    return is_object($err) && property_exists($err, 'name') && $err->name === 'AbortError';
}

/**
 * @param array<string, mixed> $raw
 * @return array{event: array<string, mixed>, error: APIError}
 */
function streamErrorEvent(array $raw, ?string $requestId): array
{
    $details = isset($raw['error']) && is_record($raw['error']) ? $raw['error'] : $raw;
    $codeRaw = $details['code'] ?? $raw['code'] ?? null;
    $statusRaw = $raw['status'] ?? $details['status'] ?? null;
    $statusNum = is_int($statusRaw) ? $statusRaw : leadingInt($statusRaw ?? $codeRaw);
    $message = isset($details['message']) && is_string($details['message'])
        ? $details['message']
        : (isset($raw['message']) && is_string($raw['message']) ? $raw['message'] : 'Stream error');
    $code = $codeRaw !== null ? (string) $codeRaw : null;
    $param = isset($details['param']) && is_string($details['param']) ? $details['param'] : null;
    $type = isset($details['type']) && is_string($details['type']) ? $details['type'] : null;
    $init = ['requestId' => $requestId, 'code' => $code, 'param' => $param, 'type' => $type, 'body' => $raw];
    if ($statusNum === 529 || preg_match('/overloaded/i', $message) === 1) {
        $init['code'] = $code ?? '529';
        $error = new OverloadedError($message, $init);
    } elseif ($statusNum !== null && $statusNum >= 400 && $statusNum < 600) {
        $error = errorFromStatus($statusNum, $message, $init);
    } else {
        $error = new APIError($message, $init);
    }
    $event = $raw;
    $event['type'] = 'error';
    $event['error'] = $error;

    return ['event' => $event, 'error' => $error];
}

function leadingInt(mixed $value): ?int
{
    if (is_int($value)) {
        return $value;
    }
    if (! is_string($value) && ! is_float($value)) {
        return null;
    }
    if (preg_match('/^\s*([+-]?\d+)/', (string) $value, $matches) !== 1) {
        return null;
    }

    return (int) $matches[1];
}
