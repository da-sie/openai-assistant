<?php

namespace DaSie\OpenaiAssistant\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \DaSie\OpenaiAssistant\OpenaiAssistant
 */
class OpenaiAssistant extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \DaSie\OpenaiAssistant\OpenaiAssistant::class;
    }
}
