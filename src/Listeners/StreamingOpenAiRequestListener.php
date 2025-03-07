<?php

namespace DaSie\Openaiassistant\Listeners;

use DaSie\Openaiassistant\Enums\CheckmarkStatus;
use DaSie\Openaiassistant\Events\AssistantUpdatedEvent;
use DaSie\Openaiassistant\Events\OpenAiRequestEvent;
use DaSie\Openaiassistant\Models\Message;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;

class StreamingOpenAiRequestListener
{
    /**
     * Obsługuje event żądania OpenAI
     *
     * @param OpenAiRequestEvent $event
     * @return void
     */
    public function handle(OpenAiRequestEvent $event): void
    {
        // Pobierz wiadomość z eventu
        $message = $event->message;

        // Sprawdź, czy wiadomość ma flagę streaming
        if (!isset($message->streaming) || $message->streaming !== true) {
            // Jeśli nie ma flagi streaming, nie obsługujemy tego eventu
            return;
        }

        try {
            // Załaduj relacje thread i assistant
            $message->load(['thread', 'thread.assistant']);

            // Pobierz klienta OpenAI
            $client = OpenAI::client(config('openai.api_key'));

            // Uruchom run z opcją streamingu
            $response = $client
                ->threads()
                ->runs()
                ->createAndStream(
                    threadId: $message->thread->openai_thread_id,
                    parameters: [
                        'assistant_id' => $message->thread->assistant->openai_assistant_id,
                    ]
                );

            // Zapisz ID runa
            $message->openai_run_id = $response->id;
            $message->saveQuietly();

            // Zmienna do przechowywania pełnej odpowiedzi
            $fullResponse = '';

            // Obsługa streamingu
            foreach ($response as $chunk) {
                // Sprawdź status
                if ($chunk->status === 'completed') {
                    // Pobierz ostatnią wiadomość
                    $lastMessages = $client
                        ->threads()
                        ->messages()
                        ->list($message->thread->openai_thread_id, [
                            'limit' => 1,
                            'order' => 'desc',
                        ]);

                    if (count($lastMessages->data) > 0) {
                        $lastMessage = $lastMessages->data[0];

                        // Zapisz pełną odpowiedź
                        $message->response = $fullResponse;
                        $message->run_status = 'completed';
                        $message->saveQuietly();

                        // Wyślij event o zakończeniu
                        event(new AssistantUpdatedEvent($message->thread->uuid, [
                            'steps' => ['processed_ai' => CheckmarkStatus::success],
                            'completed' => true,
                            'message_id' => $message->id,
                            'content' => $fullResponse
                        ]));
                    }

                    break;
                }
                // Obsługa błędu
                elseif ($chunk->status === 'failed' || $chunk->status === 'cancelled') {
                    $message->run_status = $chunk->status;
                    $message->saveQuietly();

                    // Wyślij event o błędzie
                    event(new AssistantUpdatedEvent($message->thread->uuid, [
                        'steps' => ['processed_ai' => CheckmarkStatus::failed],
                        'completed' => true,
                        'message_id' => $message->id,
                        'error' => 'Błąd podczas generowania odpowiedzi'
                    ]));

                    break;
                }
                // Obsługa fragmentu odpowiedzi
                elseif ($chunk->status === 'in_progress' && isset($chunk->delta) && isset($chunk->delta->content)) {
                    foreach ($chunk->delta->content as $content) {
                        if ($content->type === 'text') {
                            // Dodaj fragment do pełnej odpowiedzi
                            $fullResponse .= $content->text->value;

                            // Wyślij event z częściową odpowiedzią
                            event(new AssistantUpdatedEvent($message->thread->uuid, [
                                'steps' => ['processed_ai' => 'in_progress'],
                                'completed' => false,
                                'message_id' => $message->id,
                                'partial_response' => true,
                                'content' => $fullResponse
                            ]));
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Błąd podczas streamowania odpowiedzi: ' . $e->getMessage());

            // Wyślij event o błędzie
            event(new AssistantUpdatedEvent($message->thread->uuid, [
                'steps' => ['processed_ai' => CheckmarkStatus::failed],
                'completed' => true,
                'message_id' => $message->id,
                'error' => $e->getMessage()
            ]));
        }
    }
}
