<?php

namespace DaSie\Openaiassistant\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AssistantDeleteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private $assistant_id = null)
    {
    }

    public function handle(): void
    {
        if ($this->assistant_id != null) {
            $this->deleteAssistant($this->assistant_id);
        } else {
            $api = \OpenAI::client(config('openai-assistant.openai.api_key'), config('openai-assistant.openai.api_org'));
            $response = $api->assistants()->list();
            foreach ($response->data as $result) {
                $this->deleteAssistant($result->id);
            }
        }
    }

    private function deleteAssistant($id)
    {
        $api = \OpenAI::client(config('openai-assistant.openai.api_key'));

        try {
            $response = $api->assistants()->files()->list($id);
            foreach ($response->data as $result) {
                $api->assistants()->files()->delete(
                    assistantId: $id,
                    fileId: $result->id
                );
            }
            $api->assistants()->delete($id);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
        }
    }
}
