<?php

namespace DaSie\Openaiassistant\Providers;

use DaSie\Openaiassistant\Events\OpenAiRequestEvent;
use DaSie\Openaiassistant\Listeners\OpenAiRequestListener;
use DaSie\Openaiassistant\Listeners\StreamingOpenAiRequestListener;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        OpenAiRequestEvent::class => [
            OpenAiRequestListener::class,
            StreamingOpenAiRequestListener::class,
        ],
    ];

    public function boot()
    {
        parent::boot();
    }
}
