<?php

namespace DaSie\Openaiassistant\Events;

use DaSie\Openaiassistant\Models\Message;
use Illuminate\Foundation\Events\Dispatchable;

class OpenAiRequestEvent
{
    use Dispatchable;

    public Message $message;
    public function __construct($message_id)
    {
        $this->message = Message::find($message_id);
    }
}
