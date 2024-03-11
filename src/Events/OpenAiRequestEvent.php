<?php

namespace DaSie\Openaiassistant\Events;

use DaSie\Openaiassistant\Models\Message;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Events\Dispatchable;

class OpenAiRequestEvent implements ShouldQueue
{
    use Dispatchable;

    public Message $message;

    public function __construct($message_id)
    {
        $this->message = Message::find($message_id);
    }
}
