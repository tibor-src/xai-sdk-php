<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp;

const SDK_VERSION = '0.2.1';
const SDK_USER_AGENT = 'xai-sdk/' . SDK_VERSION . ' (php)';

const ENCRYPTED_REASONING = 'reasoning.encrypted_content';

/** @var list<string> */
const KNOWN_STREAM_EVENT_TYPES = [
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

/** @var list<string> */
const SERVER_TOOL_CALL_TYPES = [
    'web_search_call',
    'x_search_call',
    'custom_tool_call',
    'file_search_call',
    'code_interpreter_call',
    'mcp_call',
    'tool_search_call',
    'image_generation_call',
];

/** @var list<string> */
const INLINE_SPEECH_TAGS = [
    'pause',
    'long-pause',
    'hum-tune',
    'laugh',
    'chuckle',
    'giggle',
    'cry',
    'tsk',
    'tongue-click',
    'lip-smack',
    'breath',
    'inhale',
    'exhale',
    'sigh',
];

/** @var list<string> */
const WRAPPING_SPEECH_TAGS = [
    'soft',
    'whisper',
    'loud',
    'build-intensity',
    'decrease-intensity',
    'higher-pitch',
    'lower-pitch',
    'slow',
    'fast',
    'sing-song',
    'singing',
    'emphasis',
];

/** @var list<string> */
const KNOWN_MODEL_IDS = [
    'grok-4.20',
    'grok-4.20-0309-non-reasoning',
    'grok-4.20-0309-reasoning',
    'grok-4.20-multi-agent-0309',
    'grok-4.3',
    'grok-4.5',
    'grok-4.6',
    'grok-4.7',
    'grok-build-0.1',
];

/** @var list<string> */
const KNOWN_IMAGE_MODEL_IDS = [
    'grok-imagine-image',
    'grok-imagine-image-2.0',
    'grok-imagine-image-quality',
];

/** @var list<string> */
const KNOWN_VIDEO_MODEL_IDS = [
    'grok-imagine-video',
    'grok-imagine-video-1.5',
];

/** @var list<string> */
const KNOWN_VOICE_IDS = [
    'altair', 'ara', 'atlas', 'aurora', 'carina', 'castor', 'celeste', 'cosmo',
    'eve', 'helios', 'helix', 'iris', 'kepler', 'leo', 'liora', 'lumen', 'luna',
    'lux', 'naksh', 'orion', 'perseus', 'rex', 'rigel', 'sal', 'sirius', 'ursa',
    'zagan', 'zenith',
];

/** @var list<string> */
const KNOWN_TRANSCRIPTION_MODEL_IDS = [
    'grok-voice-transcribe-2.0',
    'grok-voice-transcribe-1.0',
];

/** @var list<string> */
const KNOWN_REALTIME_MODEL_IDS = [
    'grok-voice-latest',
    'grok-voice-think-fast-2.0',
];

function isKnownStreamEventType(string $type): bool
{
    return in_array($type, KNOWN_STREAM_EVENT_TYPES, true);
}

function isServerToolCallType(mixed $type): bool
{
    return is_string($type) && in_array($type, SERVER_TOOL_CALL_TYPES, true);
}

/**
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function webSearch(array $options = []): array
{
    return array_merge($options, ['type' => 'web_search']);
}

/**
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function xSearch(array $options = []): array
{
    return array_merge($options, ['type' => 'x_search']);
}

/** @return array{type: string} */
function codeExecution(): array
{
    return ['type' => 'code_interpreter'];
}

/**
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function collectionsSearch(array $options): array
{
    return array_merge($options, ['type' => 'file_search']);
}

/**
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function mcp(array $options): array
{
    return array_merge($options, ['type' => 'mcp']);
}

/**
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function imageGeneration(array $options = []): array
{
    return array_merge($options, ['type' => 'image_generation']);
}

/** @return array{type: string} */
function toolSearch(): array
{
    return ['type' => 'tool_search'];
}

/** @param array<string, mixed>|object $item */
function isMessage(mixed $item): bool
{
    return is_record($item) && ($item['type'] ?? null) === 'message';
}

function isReasoning(mixed $item): bool
{
    return is_record($item) && ($item['type'] ?? null) === 'reasoning';
}

function isFunctionCall(mixed $item): bool
{
    return is_record($item) && ($item['type'] ?? null) === 'function_call';
}

function isImageGenerationCall(mixed $item): bool
{
    return is_record($item) && ($item['type'] ?? null) === 'image_generation_call';
}

/**
 * @param list<array<string, mixed>> $output
 */
function toText(array $output): string
{
    return Porcelain::toText($output);
}

/**
 * @param list<array<string, mixed>> $output
 * @return list<array<string, mixed>>
 */
function toInput(array $output): array
{
    return Porcelain::toInput($output);
}

function parsePartialJson(string $text): mixed
{
    return PartialJson::parse($text);
}

/** @return list<string> */
function checkSpeechText(string $text): array
{
    return SpeechTags::check($text);
}

function stripInvalidSpeechTags(string $text): string
{
    return SpeechTags::stripInvalid($text);
}

/**
 * @param array<string, mixed>|list<mixed> $value
 */
function is_record(mixed $value): bool
{
    if (! is_array($value)) {
        return false;
    }

    if ($value === []) {
        return true;
    }

    return ! array_is_list($value);
}

function is_list_array(mixed $value): bool
{
    return is_array($value) && array_is_list($value);
}
