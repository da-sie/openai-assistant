<?php

namespace DaSie\Openaiassistant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

/**
 * @method runWithStreaming(Message $message, callable $streamCallback): void
 */
class Thread extends Model
{
    protected $guarded = [];

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
                        'name' => $assistant->name
                    ] : null
                ]);
                
                $client = \OpenAI::client(config('openai.api_key'));

                if (!$assistant || !$assistant->openai_assistant_id) {
                    throw new \Exception('Asystent nie jest poprawnie skonfigurowany');
                }

                // Utwórz wątek w OpenAI
                Log::info('Wysyłanie żądania do OpenAI...');
                
                $response = $client->threads()->create([
                    'messages' => []
                ]);

                Log::info('Odpowiedź z OpenAI:', [
                    'response' => $response ? json_decode(json_encode($response), true) : null
                ]);

                if (!$response || !isset($response->id)) {
                    throw new \Exception('Nie udało się utworzyć wątku w OpenAI');
                }

                $thread->openai_thread_id = $response->id;
                $thread->status = 'created';
                $thread->saveQuietly();

                Log::info('Wątek utworzony pomyślnie:', [
                    'thread_id' => $response->id,
                    'assistant_id' => $assistant->openai_assistant_id,
                    'status' => $thread->status
                ]);
            } catch (\Exception $e) {
                Log::error('Błąd podczas tworzenia wątku: ' . $e->getMessage(), [
                    'exception' => $e,
                    'assistant_id' => $assistant->id ?? null,
                    'trace' => $e->getTraceAsString()
                ]);

                throw $e;
            }
        });
    }


    /**
     * Tworzy nową wiadomość w wątku i wysyła ją do OpenAI
     * 
     * @param array $attributes Atrybuty wiadomości (prompt, response_type)
     * @param mixed|null $userable Opcjonalny model użytkownika
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
            Log::error('Błąd podczas tworzenia wiadomości: ' . $e->getMessage(), [
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
     * @param Message $message Wiadomość, dla której uruchamiamy asystenta
     * @return Message Wiadomość z odpowiedzią asystenta
     */
    public function run(Message $message): Message
    {
        try {
            // Upewnij się, że relacja assistant jest załadowana
            if (!$this->relationLoaded('assistant')) {
                $this->load('assistant');
            }

            if (!$this->assistant || !$this->assistant->openai_assistant_id) {
                throw new \Exception('Asystent nie jest poprawnie skonfigurowany');
            }

            $client = \OpenAI::client(config('openai.api_key'));

            // Sprawdź aktywne runy
            $runs = $client->threads()->runs()->list(
                threadId: $this->openai_thread_id,
                parameters: [
                    'limit' => 1,
                    'order' => 'desc'
                ]
            );

            if (!empty($runs->data)) {
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
                    'content' => $message->prompt
                ]
            );

            $message->openai_message_id = $messageResponse->id;
            $message->saveQuietly();

            // Uruchom wątek
            $run = $client->threads()->runs()->create(
                threadId: $this->openai_thread_id,
                parameters: [
                    'assistant_id' => $this->assistant->openai_assistant_id,
                    'instructions' => 'Przeszukaj dostępne pliki, aby znaleźć odpowiedź na pytanie. Odpowiedz tylko na podstawie znalezionych informacji, nie dodawaj własnych domysłów. Odpowiedź powinna być zwięzła i zawierać wszystkie istotne szczegóły z wyszukanych dokumentów.'
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

                    if (!is_object($run)) {
                        Log::error('Nieprawidłowa odpowiedź z OpenAI:', [
                            'run' => $run,
                            'thread_id' => $this->openai_thread_id,
                            'run_id' => $runId
                        ]);
                        throw new \Exception('Nieprawidłowa odpowiedź z OpenAI');
                    }

                    $status = $run->status;
                    ray('Status uruchomienia:', [
                        'status' => $status,
                        'run_id' => $runId,
                        'required_action' => $run->required_action ?? null,
                        'last_error' => $run->last_error ?? null
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

                        if (!is_object($run)) {
                            Log::error('Nieprawidłowa odpowiedź z OpenAI po submitToolOutputs:', [
                                'run' => $run,
                                'thread_id' => $this->openai_thread_id,
                                'run_id' => $runId
                            ]);
                            throw new \Exception('Nieprawidłowa odpowiedź z OpenAI po submitToolOutputs');
                        }

                        $status = $run->status;
                    }
                } catch (\Exception $e) {
                    Log::error('Błąd podczas sprawdzania statusu runa: ' . $e->getMessage(), [
                        'exception' => $e,
                        'thread_id' => $this->openai_thread_id,
                        'run_id' => $runId
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
                        'after' => $message->openai_message_id
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
            Log::error('Błąd podczas uruchamiania asystenta: ' . $e->getMessage(), [
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

    /**
     * Uruchamia wątek z asystentem i streamuje odpowiedź
     * 
     * @param Message $message Wiadomość, dla której uruchamiamy asystenta
     * @param callable $streamCallback Callback wywoływany dla każdego fragmentu odpowiedzi
     * @return void
     */
    public function runWithStreaming(Message $message, callable $streamCallback): void
    {
        try {
            // Upewnij się, że relacja assistant jest załadowana
            if (!$this->relationLoaded('assistant')) {
                $this->load('assistant');
            }

            if (!$this->assistant || !$this->assistant->openai_assistant_id) {
                throw new \Exception('Asystent nie jest poprawnie skonfigurowany');
            }

            $client = \OpenAI::client(config('openai.api_key'));

            // Uruchom wątek ze streamingiem
            $stream = $client->threads()->runs()->createStreamed(
                threadId: $this->openai_thread_id,
                parameters: [
                    'assistant_id' => $this->assistant->openai_assistant_id,
                ]
            );

            // Inicjalizacja zmiennej $run przed pętlą
            $run = null;
            $runCompleted = false;

            do {
                foreach ($stream as $response) {
                    // Logowanie dla debugowania
                    Log::debug('OpenAI event: ' . $response->event);

                    switch ($response->event) {
                        case 'thread.run.created':
                        case 'thread.run.queued':
                        case 'thread.run.in_progress':
                        case 'thread.run.completed':
                            $run = $response->response;
                            if ($response->event === 'thread.run.completed') {
                                $runCompleted = true;
                            }
                            break;
                        case 'thread.run.cancelling':
                            $run = $response->response;
                            break;
                        case 'thread.run.expired':
                        case 'thread.run.cancelled':
                        case 'thread.run.failed':
                            $run = $response->response;
                            $runCompleted = true; // Zakończ pętlę
                            break;
                        case 'thread.run.requires_action':
                            $run = $response->response;

                            // Obsługa wymaganych akcji narzędzi
                            if (isset($run->requiredAction) && isset($run->requiredAction->submitToolOutputs)) {
                                $toolCalls = $run->requiredAction->submitToolOutputs->toolCalls ?? [];
                                $toolOutputs = [];

                                // Tutaj można dodać logikę obsługi różnych narzędzi
                                foreach ($toolCalls as $toolCall) {
                                    // Przykładowa implementacja - w rzeczywistości powinna być bardziej rozbudowana
                                    // i obsługiwać różne typy narzędzi
                                    $toolOutputs[] = [
                                        'tool_call_id' => $toolCall->id,
                                        'output' => json_encode(['result' => 'Przykładowa odpowiedź narzędzia']),
                                    ];
                                }

                                // Nadpisz strumień nowym strumieniem po przesłaniu wyników narzędzi
                                $stream = $client->threads()->runs()->submitToolOutputsStreamed(
                                    threadId: $run->threadId,
                                    runId: $run->id,
                                    parameters: [
                                        'tool_outputs' => $toolOutputs,
                                    ]
                                );
                            }
                            break;
                        case 'thread.message.created':
                        case 'thread.message.delta':
                            // Obsługa wiadomości w trakcie streamingu
                            if (
                                $response->event === 'thread.message.delta' &&
                                isset($response->response->delta) &&
                                isset($response->response->delta->content)
                            ) {

                                foreach ($response->response->delta->content as $content) {
                                    if ($content->type === 'text' && isset($content->text->value)) {
                                        // Wywołaj callback z fragmentem odpowiedzi
                                        $streamCallback($content->text->value, $message);
                                    }
                                }
                            }
                            break;
                    }
                }
            } while ($run && !$runCompleted);

            // Pobierz wiadomości po zakończeniu uruchomienia
            if ($run && ($run->status === "completed" || $runCompleted)) {
                try {
                    // Pobierz wiadomości wygenerowane przez asystenta
                    $messages = $client->threads()->messages()->list(
                        threadId: $this->openai_thread_id,
                        parameters: [
                            'order' => 'asc',
                            'after' => $message->openai_message_id // Pobierz tylko wiadomości po ostatniej wysłanej
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

                                // Wywołaj callback z fragmentem odpowiedzi, jeśli nie było to już obsłużone w streamingu
                                $streamCallback($contentPart->text->value, $message);
                            } elseif ($contentPart->type === 'image') {
                                // Obsługa obrazów, jeśli są wspierane
                                $content .= "[OBRAZ]";
                                // Możesz dodać dodatkową logikę obsługi obrazów
                            }
                        }

                        // Zapisz odpowiedź i zaktualizuj status
                        $message->response = $content;
                        $message->run_status = 'completed';
                        $message->saveQuietly();

                        // Oznacz zakończenie streamingu
                        $streamCallback(null, $message, true);
                    } else {
                        // Brak wiadomości od asystenta
                        Log::warning('Nie znaleziono wiadomości od asystenta po zakończeniu run');
                        $message->run_status = 'completed_no_response';
                        $message->saveQuietly();
                        $streamCallback(null, $message, true, new \Exception("Brak odpowiedzi od asystenta"));
                    }
                } catch (\Exception $e) {
                    // Obsługa błędu podczas pobierania wiadomości
                    Log::error('Błąd podczas pobierania wiadomości: ' . $e->getMessage());
                    $message->run_status = 'completed_with_error';
                    $message->saveQuietly();
                    $streamCallback(null, $message, true, $e);
                }
            } else if ($run) {
                // Obsługa innych statusów zakończenia
                $status = $run->status ?? 'unknown';
                Log::warning("Run zakończony ze statusem: {$status}");
                $message->run_status = $status;
                $message->saveQuietly();
                $streamCallback(null, $message, true, new \Exception("Run zakończony ze statusem: {$status}"));
            } else {
                // Brak obiektu run
                Log::error('Brak obiektu run po zakończeniu streamingu');
                $message->run_status = 'failed_no_run';
                $message->saveQuietly();
                $streamCallback(null, $message, true, new \Exception("Nie udało się utworzyć run"));
            }
        } catch (\Exception $e) {
            // Loguj błąd
            Log::error('Błąd podczas streamowania odpowiedzi: ' . $e->getMessage(), [
                'exception' => $e,
                'thread_id' => $this->id,
                'openai_thread_id' => $this->openai_thread_id,
                'message_id' => $message->id ?? null,
            ]);

            // Aktualizuj status wiadomości
            $message->run_status = 'failed';
            $message->saveQuietly();

            // Wywołaj callback z informacją o błędzie
            $streamCallback(null, $message, true, $e);
        }
    }

    /**
     * Dodaje plik do wątku w OpenAI
     * 
     * @param string $fileId ID pliku w OpenAI
     * @return void
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
            if (!in_array($fileId, $currentFileIds)) {
                $currentFileIds[] = $fileId;
            }

            // Zaktualizuj asystenta z nową listą plików
            $assistantResponse = $client->assistants()->modify($assistant->openai_assistant_id, [
                'file_ids' => $currentFileIds,
                'tools' => [
                    ['type' => 'file_search']
                ]
            ]);

            ray('Plik dodany do asystenta:', [
                'assistant_id' => $assistant->openai_assistant_id,
                'file_id' => $fileId,
                'all_files' => $currentFileIds
            ]);

            // Dodaj plik do wątku
            $messageResponse = $client->threads()->messages()->create(
                threadId: $this->openai_thread_id,
                parameters: [
                    'role' => 'user',
                    'content' => 'Załączam plik do analizy. Proszę przeanalizować jego zawartość.',
                    'metadata' => [
                        'file_id' => $fileId
                    ]
                ]
            );

            ray('Wiadomość dodana do wątku:', [
                'message_id' => $messageResponse->id,
                'thread_id' => $this->openai_thread_id,
                'file_id' => $fileId
            ]);

            // Uruchom asystenta, aby przetworzyć plik
            $run = $client->threads()->runs()->create(
                threadId: $this->openai_thread_id,
                parameters: [
                    'assistant_id' => $assistant->openai_assistant_id,
                    'instructions' => 'Przeanalizuj załączony plik i potwierdź jego zawartość.'
                ]
            );

            ray('Uruchomiono asystenta:', [
                'run_id' => $run->id,
                'status' => $run->status
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
                'status' => $status
            ]);
        } catch (\Exception $e) {
            Log::error('Błąd podczas dodawania pliku do wątku: ' . $e->getMessage(), [
                'exception' => $e,
                'thread_id' => $this->id,
                'openai_thread_id' => $this->openai_thread_id,
                'file_id' => $fileId
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
