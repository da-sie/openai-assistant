<?php

namespace DaSie\Openaiassistant\Traits;

use DaSie\Openaiassistant\Models\Assistant;
use DaSie\Openaiassistant\Models\Thread;
use Illuminate\Support\Str;

trait WithAssistant
{

    public function newThread(Assistant $assistant)
    {
        return $this->threads()->create([
            'assistant_id' => $assistant->id,
            'uuid' => Str::uuid(),
        ]);
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
