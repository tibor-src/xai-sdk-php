<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk;

/**
 * Known model IDs from the official SDK. Additional model strings are accepted at runtime.
 */
final class Models
{
    public const KNOWN_MODEL_IDS = [
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

    public const KNOWN_IMAGE_MODEL_IDS = [
        'grok-imagine-image',
        'grok-imagine-image-2.0',
        'grok-imagine-image-quality',
    ];

    public const KNOWN_VIDEO_MODEL_IDS = [
        'grok-imagine-video',
        'grok-imagine-video-1.5',
    ];
}
