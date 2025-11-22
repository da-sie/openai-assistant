<?php

namespace DaSie\Openaiassistant\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AssistantUpdatedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(private $uuid, private $data = [])
    {
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('ai_assistant_update.'.$this->uuid),
        ];
    }

    public function broadcastWith(): array
    {
        return $this->data;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getData(): array
    {
        return $this->data;
    }

}
