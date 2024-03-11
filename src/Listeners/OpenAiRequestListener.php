<?php

namespace DaSie\Openaiassistant\Listeners;

use DaSie\Openaiassistant\Enums\CheckmarkStatus;
use DaSie\Openaiassistant\Events\AssistantUpdatedEvent;
use DaSie\Openaiassistant\Events\OpenAiRequestEvent;
use DaSie\Openaiassistant\Jobs\AssistantRequestJob;
use OpenAI\Client;
use OpenAI\Responses\Threads\Messages\ThreadMessageListResponse;
use OpenAI\Responses\Threads\Runs\Steps\ThreadRunStepListResponse;

class OpenAiRequestListener
{

    private Client $client;
    private string $thread_id;
    private string $run_id;

    public function __construct()
    {
        $this->client = \OpenAI::client(config('openai.api_key'));
    }

    public function handle(OpenAiRequestEvent $event): void
    {
        $message = $event->message;

        $this->thread_id = $message->thread->openai_thread_id;
        $this->run_id = $message->openai_run_id;

        $response = $this->getResponseByRun();

        if ($response->data && count($response->data) > 0) {
            $status = $response->data[0]->status;
            $message->run_status = $status;

            switch ($status) {
                case 'completed':
                    $message->response = $this->validateResponse($message, $this->getLastMessage());
                    $message->save();
                    event(new AssistantUpdatedEvent($message->thread->uuid, ['steps' => ['processed_ai' => CheckmarkStatus::success], 'completed' => true]));
                    break;
                case 'in_progress':
                    $message->save();
                    AssistantRequestJob::dispatch($message->id)->delay(now()->addSeconds(2));
                    break;
                default:
                    $message->save();
                    event(new AssistantUpdatedEvent($message->thread->uuid, ['steps' => ['processed_ai' => CheckmarkStatus::failed], 'completed' => true]));
                    break;
            }

        } else {
            AssistantRequestJob::dispatch($message->id)->delay(now()->addSeconds(2));
        }
    }

    private function getLastMessage(): string
    {
        $messages = $this->getMessages();
        if (count($messages->data) == 0) {
            return '';
        }

        return $messages->data[0]->content[0]->text->value;
    }

    private function getMessages(): ThreadMessageListResponse
    {
        return $this->client
            ->threads()
            ->messages()
            ->list($this->thread_id, [
                'limit' => 10,
            ]);
    }

    private function getResponseByRun(): ThreadRunStepListResponse
    {
        return $this->client
            ->threads()
            ->runs()
            ->steps()
            ->list(
                threadId: $this->thread_id,
                runId: $this->run_id,
                parameters: [
                    'limit' => 10,
                ]
            );
    }

    private function validateResponse($message, $response)
    {
        if ($message->response_type == 'json') {
            $response = str_replace(['```json', '```'], [], $response);
        }

        return $response;
    }
}
