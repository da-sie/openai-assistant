<?php

namespace DaSie\Openaiassistant\Helpers;

use DaSie\Openaiassistant\Enums\CheckmarkStatus;
use DaSie\Openaiassistant\Enums\RequestMode;
use DaSie\Openaiassistant\Events\AssistantUpdatedEvent;
use DaSie\Openaiassistant\Models\Assistant;

class OpenAiHelper
{
    public $response;

    public $client;
    public string $engine = '';
    public string $currentMessageId = '';
    public string $runId = '';
    private string $filePath;

    public Assistant $assistant;

    public function __construct($engine = null)
    {
        $this->client = \OpenAI::client(config('openai.api_key'));
        if (!$engine) {
            $this->engine = config('openai-assistant.assistant.engine');
        }
    }

    public function setAssistant(Assistant $assistant): void
    {
        $this->assistant = $assistant;
    }

    public function createAssistant(string $prePrompt): void
    {
        try {
            $assistant = $this->client->assistants()->create([
                'instructions' => $prePrompt,
                'name' => 'Assistant',
                'tools' => [
                    [
                        'type' => 'retrieval'
                    ]
                ],
                'model' => $this->engine
            ]);
            $this->assistant->assistant_id = $assistant->id;
            $this->assistant->save();
        } catch (\Exception $e) {
            ray($e->getMessage());
            event(new AssistantUpdatedEvent($this->assistant->uuid, ['steps' => ['initialized_ai' => CheckmarkStatus::failed]]));
        }
    }

    public function createThread(): void
    {
        try {
            $response = $this->client->threads()->create([]);
            $this->assistant->thread_id = $response->id;
            $this->assistant->save();
        } catch (\Exception $e) {
            event(new AssistantUpdatedEvent($this->assistant->uuid, ['steps' => ['initialized_ai' => CheckmarkStatus::failed]]));
        }
    }

    public function attachFile($path): void
    {
        $response = $this->client->files()->upload([
            'purpose' => 'assistants',
            'file' => fopen($path, 'r')
        ]);
        $fileId = $response->id;

        $this->client
            ->assistants()
            ->files()
            ->create($this->assistant->assistant_id, [
                'file_id' => $fileId
            ]);
    }

    public function sendMessage($message): void
    {
        $response = $this->client
            ->threads()
            ->messages()
            ->create($this->assistant->thread_id, [
                'role' => 'user',
                'content' => $message
            ]);
        $this->currentMessageId = $response->id;
        $this->run();
    }

    public function run(): void
    {
        $response = $this->client
            ->threads()
            ->runs()
            ->create(
                threadId: $this->assistant->thread_id,
                parameters: [
                    'assistant_id' => $this->assistant->assistant_id
                ]
            );
        $this->runId = $response->id;
    }

    public function initialize(string $prePrompt): void
    {
        if (!$this->assistant->assistant_id) {
            $this->createAssistant($prePrompt);
        }

        if (!$this->assistant->thread_id) {
            $this->createThread();

            $this->saveContentToFile();

            $this->attachFile($this->filePath);
            unlink($this->filePath);
        }

        event(new AssistantUpdatedEvent($this->assistant->uuid, ['steps' => ['initialized_ai' => CheckmarkStatus::success]]));
    }

    public function getResponseByRun($runId)
    {
        return $this->client
            ->threads()
            ->runs()
            ->steps()
            ->list(
                threadId: $this->threadId,
                runId: $runId,
                parameters: [
                    'limit' => 10
                ]
            );
    }

    public function getMessages()
    {
        return $this->client
            ->threads()
            ->messages()
            ->list($this->threadId, [
                'limit' => 10
            ]);
    }

    public function getLastMessage()
    {
        $messages = $this->getMessages();
        if (count($messages->data) == 0) {
            return '';
        }
        return $messages->data[0]->content[0]->text->value;
    }

    private function saveContentToFile(): void
    {
        $this->filePath = storage_path('app/public/' . $this->assistant->uuid . '.' . (RequestMode::from($this->assistant->request_mode))->file_extension());
        file_put_contents($this->filePath, $this->assistant->content);
    }

}

