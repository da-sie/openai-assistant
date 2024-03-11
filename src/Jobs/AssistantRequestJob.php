<?php

namespace DaSie\Openaiassistant\Jobs;

use App\Events\OpenAiRequestEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AssistantRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private $messageId)
    {
    }

    public function handle(): void
    {
        event(new OpenAiRequestEvent($this->messageId));

    }
}
