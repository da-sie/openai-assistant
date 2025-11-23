<?php

declare(strict_types=1);

namespace DaSie\Openaiassistant\Traits;

use DaSie\Openaiassistant\Enums\CheckmarkStatus;
use DaSie\Openaiassistant\Events\AssistantUpdatedEvent;
use DaSie\Openaiassistant\Services\ToolCallHandler;
use Illuminate\Support\Facades\Log;
use OpenAI\Responses\Threads\Messages\Delta\ThreadMessageDeltaResponse;
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
        $this->ensureClient();

        Log::info('Run wymaga akcji - przetwarzanie tool calls', [
            'run_id' => $response->id,
            'thread_id' => $response->threadId,
        ]);

        if (!isset($response->requiredAction) || !isset($response->requiredAction->submitToolOutputs)) {
            Log::warning('Brak wymaganych akcji w odpowiedzi', [
                'run_id' => $response->id,
            ]);
            return;
        }

        $toolCalls = $response->requiredAction->submitToolOutputs->toolCalls ?? [];

        if (empty($toolCalls)) {
            Log::warning('Pusta lista tool calls', [
                'run_id' => $response->id,
            ]);
            return;
        }

        Log::info('Przetwarzanie tool calls w trybie streaming', [
            'run_id' => $response->id,
            'tool_calls_count' => count($toolCalls),
            'tool_names' => array_map(fn($tc) => $tc->function->name ?? 'unknown', $toolCalls),
        ]);

        // Emit event to notify about tool processing
        event(new AssistantUpdatedEvent($this->uuid, [
            'steps' => ['processed_ai' => CheckmarkStatus::processing],
            'completed' => false,
            'message_id' => $this->message?->id,
            'tool_calls' => array_map(fn($tc) => $tc->function->name ?? 'unknown', $toolCalls),
            'is_processing_tools' => true,
        ]));

        try {
            // Use ToolCallHandler to process tool calls
            $toolHandler = app(ToolCallHandler::class);
            $toolOutputs = $toolHandler->handle($toolCalls);

            Log::info('Tool outputs przygotowane w streaming', [
                'run_id' => $response->id,
                'outputs_count' => count($toolOutputs),
            ]);

            // Submit tool outputs back to OpenAI
            $this->client->threads()->runs()->submitToolOutputs(
                threadId: $response->threadId,
                runId: $response->id,
                parameters: [
                    'tool_outputs' => $toolOutputs,
                ]
            );

            Log::info('Tool outputs wysłane do OpenAI', [
                'run_id' => $response->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Błąd podczas przetwarzania tool calls w streaming', [
                'run_id' => $response->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            event(new AssistantUpdatedEvent($this->uuid, [
                'steps' => ['processed_ai' => CheckmarkStatus::failed],
                'completed' => true,
                'error' => 'Failed to process tool calls: ' . $e->getMessage(),
                'message_id' => $this->message?->id,
            ]));

            throw $e;
        }
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
