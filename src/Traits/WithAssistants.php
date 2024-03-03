<?php

namespace DaSie\Openaiassistant\Traits;

use DaSie\Openaiassistant\Models\Assistant;

trait WithAssistants
{
    public function assistants()
    {
        return $this->morphMany(Assistant::class, 'model');
    }

    public function lastAssistant()
    {
        return $this->assistants()->latest()->first();
    }

    private function sanitizeContent($content): string
    {
        return (! is_string($content)) ? json_encode($content) : $content;
    }

    public function generateAssistant($data = [])
    {

        if (! is_array($data)) {
            throw new \Exception('When generating assistant, data parameter must be type of array');
        }

        if (isset($data['content'])) {
            $data['content'] = $this->sanitizeContent($data['content']);
        }

        if (json_validate($data['content'])) {
            $data['request_mode'] = 'json';
        } else {
            $data['request_mode'] = 'text';
        }

        $data = array_merge(['uuid' => (string) \Str::uuid(), 'status' => 'initialized'], $data);

        return $this->assistants()->create($data);
    }

    public function hasAssistants()
    {
        return $this->assistants()->exists();
    }
}
