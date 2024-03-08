<?php

namespace DaSie\Openaiassistant\Traits;

use DaSie\Openaiassistant\Models\Thread;

trait WithAssistant
{
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
