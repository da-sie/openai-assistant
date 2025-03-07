<?php

namespace DaSie\Openaiassistant\Listeners;

use DaSie\Openaiassistant\Enums\CheckmarkStatus;
use DaSie\Openaiassistant\Events\AssistantUpdatedEvent;
use DaSie\Openaiassistant\Events\OpenAiRequestEvent;
use DaSie\Openaiassistant\Jobs\AssistantRequestJob;
use DaSie\Openaiassistant\Models\Message;
use Illuminate\Support\Facades\Log;

class OpenAiRequestListener
{
    public function handle(OpenAiRequestEvent $event): void
    {
        $message = $event->message;
        
        // Sprawdź, czy streaming jest włączony w konfiguracji
        $useStreaming = config('openai-assistant.streaming', false);
        
        if ($useStreaming) {
            // Użyj streamingu
            $this->handleWithStreaming($message);
        } else {
            // Użyj standardowej metody bez streamingu
            $this->handleWithoutStreaming($message);
        }
    }
    
    /**
     * Obsługuje event z wykorzystaniem streamingu
     * 
     * @param Message $message
     * @return void
     */
    protected function handleWithStreaming(Message $message): void
    {
        try {
            // Uruchom wątek ze streamingiem
            $message->runWithStreaming(function ($chunk, $message, $isCompleted = false, $error = null) {
                if ($error) {
                    // Obsługa błędu
                    event(new AssistantUpdatedEvent($message->thread->uuid, [
                        'steps' => ['processed_ai' => CheckmarkStatus::failed],
                        'completed' => true,
                        'message_id' => $message->id,
                        'error' => $error->getMessage()
                    ]));
                    return;
                }
                
                if ($isCompleted) {
                    // Obsługa zakończenia
                    event(new AssistantUpdatedEvent($message->thread->uuid, [
                        'steps' => ['processed_ai' => CheckmarkStatus::success],
                        'completed' => true,
                        'message_id' => $message->id
                    ]));
                    return;
                }
                
                // Obsługa fragmentu odpowiedzi
                event(new AssistantUpdatedEvent($message->thread->uuid, [
                    'steps' => ['processed_ai' => 'in_progress'],
                    'completed' => false,
                    'message_id' => $message->id,
                    'partial_response' => true
                ]));
            });
        } catch (\Exception $e) {
            Log::error('Błąd podczas streamowania odpowiedzi: ' . $e->getMessage());
            
            // Obsługa błędu
            event(new AssistantUpdatedEvent($message->thread->uuid, [
                'steps' => ['processed_ai' => CheckmarkStatus::failed],
                'completed' => true,
                'message_id' => $message->id,
                'error' => $e->getMessage()
            ]));
        }
    }
    
    /**
     * Obsługuje event bez wykorzystania streamingu (standardowa metoda)
     * 
     * @param Message $message
     * @return void
     */
    protected function handleWithoutStreaming(Message $message): void
    {
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
                    event(new AssistantUpdatedEvent($message->thread->uuid, ['steps' => ['processed_ai' => CheckmarkStatus::success], 'completed' => true, 'message_id' => $message->id]));
                    break;
                case 'in_progress':
                    $message->save();
                    AssistantRequestJob::dispatch($message->id)->delay(now()->addSeconds(2));
                    break;
                default:
                    $message->save();
                    event(new AssistantUpdatedEvent($message->thread->uuid, ['steps' => ['processed_ai' => CheckmarkStatus::failed], 'completed' => true, 'message_id' => $message->id]));
                    break;
            }

        } else {
            AssistantRequestJob::dispatch($message->id)->delay(now()->addSeconds(2));
        }
    }
} 