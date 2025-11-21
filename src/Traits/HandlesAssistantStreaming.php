<?php

declare(strict_types=1);

namespace DaSie\Openaiassistant\Traits;

use DaSie\Openaiassistant\Enums\CheckmarkStatus;
use DaSie\Openaiassistant\Events\AssistantUpdatedEvent;
use Illuminate\Support\Facades\Log;
use OpenAI\Responses\Threads\Messages\ThreadMessageDeltaResponse;
use OpenAI\Responses\Threads\Messages\ThreadMessageResponse;
use OpenAI\Responses\Threads\Runs\ThreadRunResponse;

trait HandlesAssistantStreaming
{
    protected function ensureClient(): void
    {
        if (! isset($this->client)) {
            $this->client = \OpenAI::client(config('openai.api_key'));
        }
    }

    public function handleStreamedRun(string $threadId, string $assistantId, ?callable $onDelta = null, ?string $instructions = null): void
    {
        $this->ensureClient();

        try {
            $parameters = ['assistant_id' => $assistantId];

            // Użyj przekazanych instrukcji tylko jeśli podano
            if ($instructions) {
                $parameters['instructions'] = $instructions;
            }

            $stream = $this->client->threads()->runs()->createStreamed(
                threadId: $threadId,
                parameters: $parameters
            );

            $fullResponse = '';

            foreach ($stream as $response) {
                match ($response->event) {
                    'thread.run.created' => $this->handleRunCreated($response->response),
                    'thread.run.queued' => $this->handleRunQueued($response->response),
                    'thread.run.in_progress' => $this->handleRunInProgress($response->response),
                    'thread.run.requires_action' => $this->handleRunRequiresAction($response->response),
                    'thread.message.created' => $this->handleMessageCreated($response->response),
                    'thread.message.in_progress' => $this->handleMessageInProgress($response->response),
                    'thread.message.delta' => $this->handleMessageDelta($response->response, $onDelta, $fullResponse),
                    'thread.message.completed' => $this->handleMessageCompleted($response->response, $fullResponse),
                    'thread.run.completed' => $this->handleRunCompleted($response->response),
                    'thread.run.failed' => $this->handleRunFailed($response->response),
                    'thread.run.cancelled' => $this->handleRunCancelled($response->response),
                    'thread.run.expired' => $this->handleRunExpired($response->response),
                    default => $this->handleUnknownEvent($response->event, $response->response),
                };
            }
        } catch (\Exception $e) {
            Log::error('Błąd podczas streamowania odpowiedzi asystenta', [
                'exception' => $e,
                'thread_id' => $threadId,
                'assistant_id' => $assistantId,
            ]);

            event(new AssistantUpdatedEvent($this->uuid, [
                'steps' => ['processed_ai' => CheckmarkStatus::failed],
                'completed' => true,
                'error' => $e->getMessage(),
            ]));

            throw $e;
        }
    }

    protected function handleRunCreated(ThreadRunResponse $response): void
    {
        Log::info('Run utworzony', [
            'run_id' => $response->id,
            'status' => $response->status,
            'thread_id' => $response->threadId,
        ]);

        // Zapisz run_id w wiadomości
        if ($this->message) {
            $this->message->openai_run_id = $response->id;
            $this->message->run_status = 'in_progress';
            $this->message->saveQuietly();
        }

        event(new AssistantUpdatedEvent($this->uuid, [
            'steps' => ['processed_ai' => CheckmarkStatus::processing],
            'completed' => false,
            'run_id' => $response->id,
            'message_id' => $this->message?->id,
        ]));
    }

    protected function handleRunQueued(ThreadRunResponse $response): void
    {
        Log::info('Run w kolejce', [
            'run_id' => $response->id,
            'status' => $response->status,
        ]);
    }

    protected function handleRunInProgress(ThreadRunResponse $response): void
    {
        Log::info('Run w trakcie', [
            'run_id' => $response->id,
            'status' => $response->status,
        ]);
    }

    protected function handleRunRequiresAction(ThreadRunResponse $response): void
    {
        // Tu możemy dodać obsługę narzędzi
        Log::info('Run wymaga akcji', [
            'run_id' => $response->id,
            'required_action' => $response->requiredAction,
        ]);
    }

    protected function handleMessageCreated(ThreadMessageResponse $response): void
    {
        Log::info('Wiadomość utworzona', [
            'message_id' => $response->id,
            'thread_id' => $response->threadId,
        ]);
    }

    protected function handleMessageInProgress(ThreadMessageResponse $response): void
    {
        Log::info('Wiadomość w trakcie', [
            'message_id' => $response->id,
            'thread_id' => $response->threadId,
        ]);
    }

    protected function handleMessageDelta(ThreadMessageDeltaResponse $delta, ?callable $onDelta, string &$fullResponse): void
    {
        // Wyciągnij content z delta response
        $content = '';
        if (isset($delta->delta->content) && count($delta->delta->content) > 0) {
            foreach ($delta->delta->content as $contentItem) {
                if ($contentItem->type === 'text' && isset($contentItem->text->value)) {
                    $content .= $contentItem->text->value;
                }
            }
        }

        if ($content !== '') {
            $fullResponse .= $content;

            if ($onDelta) {
                $onDelta($content);
            }

            event(new AssistantUpdatedEvent($this->uuid, [
                'steps' => ['processed_ai' => CheckmarkStatus::processing],
                'completed' => false,
                'content' => $content,
                'is_delta' => true,
                'message_id' => $this->message?->id,
            ]));
        }
    }

    protected function handleMessageCompleted(ThreadMessageResponse $response, string $fullResponse): void
    {
        Log::info('Wiadomość zakończona', [
            'message_id' => $response->id,
            'thread_id' => $response->threadId,
            'full_response' => $fullResponse,
        ]);

        // Tutaj możemy zapisać pełną odpowiedź do bazy
        if ($this->message) {
            $this->message->response = $fullResponse;
            $this->message->run_status = 'completed';
            $this->message->saveQuietly();
        }
    }

    protected function handleRunCompleted(ThreadRunResponse $response): void
    {
        Log::info('Run zakończony sukcesem', [
            'run_id' => $response->id,
            'status' => $response->status,
        ]);

        event(new AssistantUpdatedEvent($this->uuid, [
            'steps' => ['processed_ai' => CheckmarkStatus::success],
            'completed' => true,
            'message_id' => $this->message?->id,
        ]));
    }

    protected function handleRunFailed(ThreadRunResponse $response): void
    {
        Log::error('Run zakończony niepowodzeniem', [
            'run_id' => $response->id,
            'status' => $response->status,
            'error' => $response->lastError,
        ]);

        // Zaktualizuj status wiadomości
        if ($this->message) {
            $this->message->run_status = 'failed';
            $this->message->saveQuietly();
        }

        event(new AssistantUpdatedEvent($this->uuid, [
            'steps' => ['processed_ai' => CheckmarkStatus::failed],
            'completed' => true,
            'error' => $response->lastError,
            'message_id' => $this->message?->id,
        ]));
    }

    protected function handleRunCancelled(ThreadRunResponse $response): void
    {
        Log::warning('Run anulowany', [
            'run_id' => $response->id,
            'status' => $response->status,
        ]);

        event(new AssistantUpdatedEvent($this->uuid, [
            'steps' => ['processed_ai' => CheckmarkStatus::failed],
            'completed' => true,
            'error' => 'Run został anulowany',
            'message_id' => $this->message?->id,
        ]));
    }

    protected function handleRunExpired(ThreadRunResponse $response): void
    {
        Log::warning('Run wygasł', [
            'run_id' => $response->id,
            'status' => $response->status,
        ]);

        event(new AssistantUpdatedEvent($this->uuid, [
            'steps' => ['processed_ai' => CheckmarkStatus::failed],
            'completed' => true,
            'error' => 'Run wygasł',
            'message_id' => $this->message?->id,
        ]));
    }

    protected function handleUnknownEvent(string $event, $response): void
    {
        Log::warning('Nieznany event', [
            'event' => $event,
            'response' => $response,
        ]);
    }
}
