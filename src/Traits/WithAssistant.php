<?php

namespace DaSie\Openaiassistant\Traits;

use DaSie\Openaiassistant\Models\Assistant;
use DaSie\Openaiassistant\Models\Thread;
use Illuminate\Support\Str;

trait WithAssistant
{

    /**
     * Creates a new thread for the assistant
     *
     * @param Assistant $assistant The assistant to create the thread for
     * @param array $initialMessages Optional initial messages to include in the thread context
     *                               Format: [['role' => 'user', 'content' => '...'], ...]
     * @return Thread
     */
    public function newThread(Assistant $assistant, array $initialMessages = [])
    {
        // Store initial messages in static property before creating thread
        if (!empty($initialMessages)) {
            Thread::$pendingInitialMessages = $initialMessages;
        }

        $thread = $this->threads()->create([
            'assistant_id' => $assistant->id,
            'uuid' => Str::uuid(),
        ]);

        // Clear the pending messages
        Thread::$pendingInitialMessages = [];

        return $thread;
    }

    public function threads()
    {
        return $this->morphMany(Thread::class, 'model');
    }

    public function latestThread()
    {
        return $this->threads()->latest()->first();
    }

    public function hasThreads()
    {
        return $this->threads()->exists();
    }
}
