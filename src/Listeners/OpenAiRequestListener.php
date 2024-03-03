<?php

namespace DaSie\Openaiassistant\Listeners;

use DaSie\Openaiassistant\Enums\CheckmarkStatus;
use DaSie\Openaiassistant\Events\OpenAiRequestEvent;
use DaSie\Openaiassistant\Events\AssistantUpdatedEvent;
use DaSie\Openaiassistant\Helpers\OpenAiHelper;

class OpenAiRequestListener
{
    public function __construct()
    {
    }

    public function handle(OpenAiRequestEvent $event): void
    {
        $message = $event->message;
        $assistant = $message->assistant;


        $api = new OpenAiHelper();
        $api->assistantId = $assistant->assistant_id;
        $api->threadId = $assistant->thread_id;
        $response = $api->getResponseByRun($message->run_id);

        if ($response->data && count($response->data) > 0) {
            $status = $response->data[0]->status;
            $message->run_status = $status;

            switch ($status) {
                case 'completed':
                    $message->response = $this->validateResponse($message, $api->getLastMessage());
                    $message->save();
                    event(new AssistantUpdatedEvent($assistant->uuid, ['steps' => ['processed_ai' => CheckmarkStatus::success], 'completed' => true]));
                    break;
                case 'in_progress':
                    $message->save();
                    sleep(2);
                    OpenAiRequestEvent::dispatch($message->id);
                    break;
                default:
                    $message->save();
                    event(new AssistantUpdatedEvent($assistant->uuid, ['steps' => ['processed_ai' => CheckmarkStatus::failed], 'completed' => true]));
                    break;
            }

        } else {
            sleep(2);
            OpenAiRequestEvent::dispatch($message->id);
        }
    }

    private function validateResponse($message, $response)
    {
        if ($message->response_type == 'json') {
            $response = str_replace(["```json", "```"], [], $response);
        }
        return $response;
    }
}
