<?php

namespace DaSie\Openaiassistant\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \DaSie\Openaiassistant\OpenaiAssistant
 */
class OpenaiAssistant extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \DaSie\Openaiassistant\OpenaiAssistant::class;
    }
}
