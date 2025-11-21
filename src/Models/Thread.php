<?php

namespace DaSie\Openaiassistant\Models;

use DaSie\Openaiassistant\Enums\CheckmarkStatus;
use DaSie\Openaiassistant\Events\AssistantUpdatedEvent;
use DaSie\Openaiassistant\Traits\HandlesAssistantStreaming;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

/**
 * @method runWithStreaming(Message $message, callable $streamCallback): void
 */
class Thread extends Model
{
    use HandlesAssistantStreaming;

    protected $guarded = [];

    protected $client;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(config('openai-assistant.table.threads'));
    }

    protected static function booted()
    {
        static::created(function ($thread) {
            try {
                $assistant = $thread->assistant;

                Log::info('Próba utworzenia wątku:', [
                    'thread_id' => $thread->id,
                    'assistant' => $assistant ? [
                        'id' => $assistant->id,
                        'openai_assistant_id' => $assistant->openai_assistant_id,
                        'name' => $assistant->name,
                    ] : null,
                ]);

                $client = \OpenAI::client(config('openai.api_key'));

                if (! $assistant || ! $assistant->openai_assistant_id) {
                    throw new \Exception('Asystent nie jest poprawnie skonfigurowany');
                }

                // Utwórz wątek w OpenAI
                Log::info('Wysyłanie żądania do OpenAI...');

                $response = $client->threads()->create([
                    'messages' => [],
                ]);

                Log::info('Odpowiedź z OpenAI:', [
                    'response' => $response ? json_decode(json_encode($response), true) : null,
                ]);

                if (! $response || ! isset($response->id)) {
                    throw new \Exception('Nie udało się utworzyć wątku w OpenAI');
                }

                $thread->openai_thread_id = $response->id;
                $thread->status = 'created';
                $thread->saveQuietly();

                Log::info('Wątek utworzony pomyślnie:', [
                    'thread_id' => $response->id,
                    'assistant_id' => $assistant->openai_assistant_id,
                    'status' => $thread->status,
                ]);
            } catch (\Exception $e) {
                Log::error('Błąd podczas tworzenia wątku: '.$e->getMessage(), [
                    'exception' => $e,
                    'assistant_id' => $assistant->id ?? null,
                    'trace' => $e->getTraceAsString(),
                ]);

                throw $e;
            }
        });
    }

    /**
     * Tworzy nową wiadomość w wątku i wysyła ją do OpenAI
     *
     * @param  array  $attributes  Atrybuty wiadomości (prompt, response_type)
     * @param  mixed|null  $userable  Opcjonalny model użytkownika
     * @return Message Utworzona wiadomość
     */
    public function createMessage(array $attributes, $userable = null): Message
    {
        try {
            // Utwórz lokalny rekord wiadomości
            $message = new Message([
                'thread_id' => $this->id,
                'prompt' => $attributes['prompt'],
                'response_type' => $attributes['response_type'],
                'assistant_id' => $this->assistant_id,
            ]);

            // Jeśli przekazano userable, dodaj relację
            if ($userable) {
                $message->userable()->associate($userable);
            }

            $message->save();

            return $message;
        } catch (\Exception $e) {
            Log::error('Błąd podczas tworzenia wiadomości: '.$e->getMessage(), [
                'exception' => $e,
                'thread_id' => $this->id,
                'openai_thread_id' => $this->openai_thread_id,
            ]);

            throw $e;
        }
    }

    /**
     * Uruchamia asystenta dla wątku i zwraca odpowiedź (bez streamingu)
     *
     * @param  Message  $message  Wiadomość, dla której uruchamiamy asystenta
     * @return Message Wiadomość z odpowiedzią asystenta
     */
    public function run(Message $message): Message
    {
        try {
            // Upewnij się, że relacja assistant jest załadowana
            if (! $this->relationLoaded('assistant')) {
                $this->load('assistant');
            }

            if (! $this->assistant || ! $this->assistant->openai_assistant_id) {
                throw new \Exception('Asystent nie jest poprawnie skonfigurowany');
            }

            $client = \OpenAI::client(config('openai.api_key'));

            // Sprawdź aktywne runy
            $runs = $client->threads()->runs()->list(
                threadId: $this->openai_thread_id,
                parameters: [
                    'limit' => 1,
                    'order' => 'desc',
                ]
            );

            if (! empty($runs->data)) {
                $latestRun = $runs->data[0];
                if (in_array($latestRun->status, ['queued', 'in_progress', 'requires_action'])) {
                    // Poczekaj na zakończenie aktywnego runa
                    while (in_array($latestRun->status, ['queued', 'in_progress', 'requires_action'])) {
                        sleep(1);
                        $latestRun = $client->threads()->runs()->retrieve(
                            threadId: $this->openai_thread_id,
                            runId: $latestRun->id
                        );
                    }
                }
            }

            // Dodaj wiadomość do wątku
            $messageResponse = $client->threads()->messages()->create(
                threadId: $this->openai_thread_id,
                parameters: [
                    'role' => 'user',
                    'content' => $message->prompt,
                ]
            );

            $message->openai_message_id = $messageResponse->id;
            $message->saveQuietly();

            // Uruchom wątek
            $run = $client->threads()->runs()->create(
                threadId: $this->openai_thread_id,
                parameters: [
                    'assistant_id' => $this->assistant->openai_assistant_id,
                    'instructions' => 'Przeszukaj dostępne pliki, aby znaleźć odpowiedź na pytanie. Odpowiedz tylko na podstawie znalezionych informacji, nie dodawaj własnych domysłów. Odpowiedź powinna być zwięzła i zawierać wszystkie istotne szczegóły z wyszukanych dokumentów.',
                ]
            );

            // Czekaj na zakończenie uruchomienia
            $status = $run->status;
            $runId = $run->id;

            while (in_array($status, ['queued', 'in_progress', 'cancelling', 'requires_action'])) {
                sleep(1); // Krótka przerwa, aby nie przeciążać API

                try {
                    $run = $client->threads()->runs()->retrieve(
                        threadId: $this->openai_thread_id,
                        runId: $runId
                    );

                    if (! is_object($run)) {
                        Log::error('Nieprawidłowa odpowiedź z OpenAI:', [
                            'run' => $run,
                            'thread_id' => $this->openai_thread_id,
                            'run_id' => $runId,
                        ]);
                        throw new \Exception('Nieprawidłowa odpowiedź z OpenAI');
                    }

                    $status = $run->status;
                    ray('Status uruchomienia:', [
                        'status' => $status,
                        'run_id' => $runId,
                        'required_action' => $run->required_action ?? null,
                        'last_error' => $run->last_error ?? null,
                    ]);

                    // Obsługa wymaganych akcji narzędzi
                    if ($status === 'requires_action' && isset($run->requiredAction)) {
                        $toolCalls = $run->requiredAction->submitToolOutputs->toolCalls ?? [];
                        $toolOutputs = [];

                        // Tutaj można dodać logikę obsługi różnych narzędzi
                        foreach ($toolCalls as $toolCall) {
                            // Przykładowa implementacja
                            $toolOutputs[] = [
                                'tool_call_id' => $toolCall->id,
                                'output' => json_encode(['result' => 'Przykładowa odpowiedź narzędzia']),
                            ];
                        }

                        // Prześlij wyniki narzędzi
                        $run = $client->threads()->runs()->submitToolOutputs(
                            threadId: $this->openai_thread_id,
                            runId: $runId,
                            parameters: [
                                'tool_outputs' => $toolOutputs,
                            ]
                        );

                        if (! is_object($run)) {
                            Log::error('Nieprawidłowa odpowiedź z OpenAI po submitToolOutputs:', [
                                'run' => $run,
                                'thread_id' => $this->openai_thread_id,
                                'run_id' => $runId,
                            ]);
                            throw new \Exception('Nieprawidłowa odpowiedź z OpenAI po submitToolOutputs');
                        }

                        $status = $run->status;
                    }
                } catch (\Exception $e) {
                    Log::error('Błąd podczas sprawdzania statusu runa: '.$e->getMessage(), [
                        'exception' => $e,
                        'thread_id' => $this->openai_thread_id,
                        'run_id' => $runId,
                    ]);
                    throw $e;
                }
            }

            // Pobierz wiadomości po zakończeniu uruchomienia
            if ($status === 'completed') {
                $messages = $client->threads()->messages()->list(
                    threadId: $this->openai_thread_id,
                    parameters: [
                        'order' => 'asc',
                        'after' => $message->openai_message_id,
                    ]
                );

                // Znajdź pierwszą wiadomość od asystenta
                $assistantMessage = null;
                foreach ($messages->data as $msg) {
                    if ($msg->role === 'assistant') {
                        $assistantMessage = $msg;
                        break;
                    }
                }

                if ($assistantMessage) {
                    // Zapisz odpowiedź w bazie danych
                    $content = '';
                    foreach ($assistantMessage->content as $contentPart) {
                        if ($contentPart->type === 'text') {
                            $content .= $contentPart->text->value;
                        }
                    }

                    $message->response = $content;
                    $message->run_status = 'completed';
                    $message->saveQuietly();

                    return $message;
                }
            }

            // Obsługa innych statusów zakończenia
            $message->run_status = $status;
            $message->saveQuietly();

            return $message;
        } catch (\Exception $e) {
            // Loguj błąd
            Log::error('Błąd podczas uruchamiania asystenta: '.$e->getMessage(), [
                'exception' => $e,
                'thread_id' => $this->id,
                'openai_thread_id' => $this->openai_thread_id,
                'message_id' => $message->id,
            ]);

            // Aktualizuj status wiadomości
            $message->run_status = 'failed';
            $message->saveQuietly();

            throw $e;
        }
    }

    public function createStreamedMessage(array $messageData, ?User $user = null): Message
    {
        $message = $this->createMessage($messageData, $user);
        $this->message = $message; // zachowujemy referencję do wiadomości dla handleMessageCompleted

        $this->handleStreamedRun(
            threadId: $this->openai_thread_id,
            assistantId: $this->assistant->openai_assistant_id,
            onDelta: function ($content) use ($message) {
                // Możemy tu dodać dodatkową logikę dla każdego chunka
                ray('Otrzymano chunk:', [
                    'content' => $content,
                    'message_id' => $message->id,
                ]);
            }
        );

        return $message;
    }

    /**
     * Uruchamia wątek z asystentem i streamuje odpowiedź
     *
     * @param  Message  $message  Wiadomość, dla której uruchamiamy asystenta
     * @param  callable  $streamCallback  Callback wywoływany dla każdego fragmentu odpowiedzi
     */
    public function runWithStreaming(Message $message, callable $streamCallback): void
    {
        try {
            ray('Rozpoczynam streamowanie odpowiedzi:', [
                'message_id' => $message->id,
                'thread_id' => $this->id,
                'assistant_id' => $this->assistant_id,
            ]);

            // Upewnij się, że relacja assistant jest załadowana
            if (! $this->relationLoaded('assistant')) {
                $this->load('assistant');
            }

            if (! $this->assistant || ! $this->assistant->openai_assistant_id) {
                ray('Błąd: Asystent nie jest poprawnie skonfigurowany');
                throw new \Exception('Asystent nie jest poprawnie skonfigurowany');
            }

            $client = \OpenAI::client(config('openai.api_key'));

            ray('Wysyłam wiadomość do OpenAI:', [
                'prompt' => $message->prompt,
                'thread_id' => $this->openai_thread_id,
            ]);

            // Dodaj wiadomość do wątku
            $messageResponse = $client->threads()->messages()->create(
                threadId: $this->openai_thread_id,
                parameters: [
                    'role' => 'user',
                    'content' => $message->prompt,
                ]
            );

            ray('Wiadomość dodana do OpenAI:', [
                'message_id' => $messageResponse->id,
                'thread_id' => $this->openai_thread_id,
            ]);

            $message->openai_message_id = $messageResponse->id;
            $message->saveQuietly();

            ray('Uruchamiam run w OpenAI:', [
                'assistant_id' => $this->assistant->openai_assistant_id,
                'thread_id' => $this->openai_thread_id,
            ]);

            // Uruchom wątek
            $run = $client->threads()->runs()->create(
                threadId: $this->openai_thread_id,
                parameters: [
                    'assistant_id' => $this->assistant->openai_assistant_id,
                ]
            );

            ray('Run utworzony:', [
                'run_id' => $run->id,
                'status' => $run->status,
            ]);

            $message->openai_run_id = $run->id;
            $message->run_status = $run->status;
            $message->saveQuietly();

            // Czekaj na zakończenie uruchomienia
            $status = $run->status;
            $runId = $run->id;

            while (in_array($status, ['queued', 'in_progress', 'requires_action'])) {
                sleep(1);
                try {
                    ray('Sprawdzam status runa:', [
                        'run_id' => $runId,
                        'current_status' => $status,
                    ]);

                    $run = $client->threads()->runs()->retrieve(
                        threadId: $this->openai_thread_id,
                        runId: $runId
                    );
                    $status = $run->status;

                    ray('Status runa zaktualizowany:', [
                        'run_id' => $runId,
                        'new_status' => $status,
                    ]);

                    // Aktualizuj status i powiadom frontend
                    $message->run_status = $status;
                    $message->saveQuietly();

                    if ($status === 'in_progress') {
                        ray('Wysyłam event o przetwarzaniu');
                        event(new AssistantUpdatedEvent($this->uuid, [
                            'steps' => ['processed_ai' => CheckmarkStatus::processing],
                            'completed' => false,
                            'message_id' => $message->id,
                        ]));
                    }

                    // Obsługa wymaganych akcji narzędzi
                    if ($status === 'requires_action' && isset($run->requiredAction)) {
                        ray('Wymagane akcje narzędzi:', [
                            'tool_calls' => $run->requiredAction->submitToolOutputs->toolCalls ?? [],
                        ]);

                        $toolCalls = $run->requiredAction->submitToolOutputs->toolCalls ?? [];
                        $toolOutputs = [];

                        foreach ($toolCalls as $toolCall) {
                            $toolOutputs[] = [
                                'tool_call_id' => $toolCall->id,
                                'output' => json_encode(['result' => 'Przykładowa odpowiedź narzędzia']),
                            ];
                        }

                        ray('Wysyłam wyniki narzędzi:', [
                            'tool_outputs' => $toolOutputs,
                        ]);

                        $run = $client->threads()->runs()->submitToolOutputs(
                            threadId: $this->openai_thread_id,
                            runId: $runId,
                            parameters: [
                                'tool_outputs' => $toolOutputs,
                            ]
                        );
                        $status = $run->status;

                        ray('Status po wysłaniu wyników narzędzi:', [
                            'new_status' => $status,
                        ]);
                    }
                } catch (\Exception $e) {
                    ray('Błąd podczas sprawdzania statusu:', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    Log::error('Błąd podczas sprawdzania statusu: '.$e->getMessage());
                    event(new AssistantUpdatedEvent($this->uuid, [
                        'steps' => ['processed_ai' => CheckmarkStatus::failed],
                        'completed' => true,
                        'message_id' => $message->id,
                    ]));
                    throw $e;
                }
            }

            if ($status === 'completed') {
                try {
                    ray('Run zakończony sukcesem, pobieram wiadomości');

                    $messages = $client->threads()->messages()->list(
                        threadId: $this->openai_thread_id,
                        parameters: [
                            'order' => 'asc',
                            'after' => $message->openai_message_id,
                        ]
                    );

                    ray('Pobrane wiadomości:', [
                        'count' => count($messages->data),
                    ]);

                    $assistantMessage = null;
                    foreach ($messages->data as $msg) {
                        if ($msg->role === 'assistant') {
                            $assistantMessage = $msg;
                            break;
                        }
                    }

                    if ($assistantMessage) {
                        ray('Znaleziono wiadomość asystenta:', [
                            'message_id' => $assistantMessage->id,
                            'content_count' => count($assistantMessage->content),
                        ]);

                        $content = '';
                        foreach ($assistantMessage->content as $contentPart) {
                            if ($contentPart->type === 'text') {
                                $content .= $contentPart->text->value;
                                ray('Wysyłam fragment odpowiedzi:', [
                                    'content' => $contentPart->text->value,
                                ]);
                                $streamCallback($contentPart->text->value, $message);
                            }
                        }

                        $message->response = $content;
                        $message->run_status = 'completed';
                        $message->saveQuietly();

                        ray('Wysyłam event o sukcesie');
                        event(new AssistantUpdatedEvent($this->uuid, [
                            'steps' => ['processed_ai' => CheckmarkStatus::success],
                            'completed' => true,
                            'message_id' => $message->id,
                        ]));

                        $streamCallback(null, $message, true);
                    } else {
                        ray('Nie znaleziono wiadomości asystenta');
                        throw new \Exception('Brak odpowiedzi od asystenta');
                    }
                } catch (\Exception $e) {
                    ray('Błąd podczas pobierania odpowiedzi:', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    Log::error('Błąd podczas pobierania odpowiedzi: '.$e->getMessage());
                    event(new AssistantUpdatedEvent($this->uuid, [
                        'steps' => ['processed_ai' => CheckmarkStatus::failed],
                        'completed' => true,
                        'message_id' => $message->id,
                    ]));
                    throw $e;
                }
            } else {
                ray('Run zakończony niepowodzeniem:', [
                    'status' => $status,
                ]);
                Log::error('Run zakończony ze statusem: '.$status);
                event(new AssistantUpdatedEvent($this->uuid, [
                    'steps' => ['processed_ai' => CheckmarkStatus::failed],
                    'completed' => true,
                    'message_id' => $message->id,
                ]));
                throw new \Exception('Run zakończony ze statusem: '.$status);
            }
        } catch (\Exception $e) {
            ray('Błąd podczas streamowania:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            Log::error('Błąd podczas streamowania: '.$e->getMessage());
            $message->run_status = 'failed';
            $message->saveQuietly();

            event(new AssistantUpdatedEvent($this->uuid, [
                'steps' => ['processed_ai' => CheckmarkStatus::failed],
                'completed' => true,
                'message_id' => $message->id,
            ]));

            $streamCallback(null, $message, true, $e);
        }
    }

    /**
     * Dodaje plik do wątku w OpenAI
     *
     * @param  string  $fileId  ID pliku w OpenAI
     */
    public function attachFile(string $fileId): void
    {
        try {
            $client = \OpenAI::client(config('openai.api_key'));

            // Pobierz aktualną konfigurację asystenta
            $assistant = $this->assistant;
            $currentAssistant = $client->assistants()->retrieve($assistant->openai_assistant_id);
            $currentFileIds = $currentAssistant->file_ids ?? [];

            // Dodaj nowy plik do listy istniejących plików
            if (! in_array($fileId, $currentFileIds)) {
                $currentFileIds[] = $fileId;
            }

            // Zaktualizuj asystenta z nową listą plików
            $assistantResponse = $client->assistants()->modify($assistant->openai_assistant_id, [
                'file_ids' => $currentFileIds,
                'tools' => [
                    ['type' => 'file_search'],
                ],
            ]);

            ray('Plik dodany do asystenta:', [
                'assistant_id' => $assistant->openai_assistant_id,
                'file_id' => $fileId,
                'all_files' => $currentFileIds,
            ]);

            // Dodaj plik do wątku
            $messageResponse = $client->threads()->messages()->create(
                threadId: $this->openai_thread_id,
                parameters: [
                    'role' => 'user',
                    'content' => 'Załączam plik do analizy. Proszę przeanalizować jego zawartość.',
                    'metadata' => [
                        'file_id' => $fileId,
                    ],
                ]
            );

            ray('Wiadomość dodana do wątku:', [
                'message_id' => $messageResponse->id,
                'thread_id' => $this->openai_thread_id,
                'file_id' => $fileId,
            ]);

            // Uruchom asystenta, aby przetworzyć plik
            $run = $client->threads()->runs()->create(
                threadId: $this->openai_thread_id,
                parameters: [
                    'assistant_id' => $assistant->openai_assistant_id,
                    'instructions' => 'Przeanalizuj załączony plik i potwierdź jego zawartość.',
                ]
            );

            ray('Uruchomiono asystenta:', [
                'run_id' => $run->id,
                'status' => $run->status,
            ]);

            // Poczekaj na zakończenie przetwarzania
            $status = $run->status;
            while (in_array($status, ['queued', 'in_progress', 'requires_action'])) {
                sleep(1);
                $run = $client->threads()->runs()->retrieve(
                    threadId: $this->openai_thread_id,
                    runId: $run->id
                );
                $status = $run->status;
            }

            ray('Zakończono przetwarzanie pliku:', [
                'status' => $status,
            ]);
        } catch (\Exception $e) {
            Log::error('Błąd podczas dodawania pliku do wątku: '.$e->getMessage(), [
                'exception' => $e,
                'thread_id' => $this->id,
                'openai_thread_id' => $this->openai_thread_id,
                'file_id' => $fileId,
            ]);

            throw $e;
        }
    }

    /**
     * Relacja do asystenta
     */
    public function assistant(): BelongsTo
    {
        return $this->belongsTo(Assistant::class);
    }

    /**
     * Relacja do wiadomości
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
