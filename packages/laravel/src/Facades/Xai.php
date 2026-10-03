<?php

declare(strict_types=1);

namespace XaiSdk\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use XaiOfficial\Sdk\SpaceXAI;

/**
 * @method static \XaiOfficial\Sdk\Resources\Responses responses()
 * @method static \XaiOfficial\Sdk\Resources\ModelsResource models()
 * @method static \XaiOfficial\Sdk\Resources\Images images()
 * @method static \XaiOfficial\Sdk\Resources\Videos videos()
 * @method static \XaiOfficial\Sdk\Resources\Files files()
 * @method static \XaiOfficial\Sdk\Resources\Batches batches()
 * @method static \XaiOfficial\Sdk\Resources\VoiceResource voice()
 * @method static \XaiOfficial\Sdk\Resources\Tokenizer tokenizer()
 * @method static \XaiOfficial\Sdk\Resources\Account account()
 *
 * @see SpaceXAI
 */
class Xai extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SpaceXAI::class;
    }
}
