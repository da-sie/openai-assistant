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
    
    /**
     * Tworzy nową wiadomość w wątku i wysyła ją do OpenAI
     * 
     * @param string $content Treść wiadomości
     * @param array $fileIds Opcjonalne identyfikatory plików do załączenia
     * @return Message Utworzona wiadomość
     */
    public function createMessage(string $content, array $fileIds = []): Message
    {
        try {
            $client = \OpenAI::client(config('openai.api_key'));
            
            // Parametry wiadomości
            $parameters = [
                'role' => 'user',
                'content' => $content,
            ];
            
            // Dodaj pliki, jeśli są
            if (!empty($fileIds)) {
                $parameters['file_ids'] = $fileIds;
            }
            
            // Wyślij wiadomość do OpenAI
            $response = $client->threads()->messages()->create(
                threadId: $this->openai_thread_id,
                parameters: $parameters
            );
            
            // Utwórz lokalny rekord wiadomości
            $message = new Message([
                'thread_id' => $this->id,
                'role' => 'user',
                'content' => $content,
                'openai_message_id' => $response->id,
                'file_ids' => $fileIds,
            ]);
            
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
            
            // Uruchom wątek
            $run = $client->threads()->runs()->create(
                threadId: $this->openai_thread_id,
                parameters: [
                    'assistant_id' => $this->assistant->openai_assistant_id,
                ]
            );
            
            // Czekaj na zakończenie uruchomienia
            $status = $run->status;
            $runId = $run->id;
            
            while (in_array($status, ['queued', 'in_progress', 'cancelling'])) {
                sleep(1); // Krótka przerwa, aby nie przeciążać API
                
                $run = $client->threads()->runs()->retrieve(
                    threadId: $this->openai_thread_id,
                    runId: $runId
                );
                
                $status = $run->status;
                
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
                    
                    $status = $run->status;
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
                ]);

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
                            if ($response->event === 'thread.message.delta' && 
                                isset($response->response->delta) && 
                                isset($response->response->delta->content)) {
                                
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
     * Tworzy nowy wątek w OpenAI i zapisuje go w bazie danych
     * 
     * @param Assistant $assistant Asystent, do którego ma być przypisany wątek
     * @param array $metadata Opcjonalne metadane wątku
     * @return Thread Utworzony wątek
     */
    public static function createWithOpenAI(Assistant $assistant, array $metadata = []): Thread
    {
        try {
            $client = \OpenAI::client(config('openai.api_key'));
            
            // Parametry wątku
            $parameters = [];
            
            // Dodaj metadane, jeśli są
            if (!empty($metadata)) {
                $parameters['metadata'] = $metadata;
            }
            
            // Utwórz wątek w OpenAI
            $response = $client->threads()->create($parameters);
            
            // Utwórz lokalny rekord wątku
            $thread = new Thread([
                'assistant_id' => $assistant->id,
                'openai_thread_id' => $response->id,
                'metadata' => $metadata,
            ]);
            
            $thread->save();
            
            // Załaduj relację asystenta
            $thread->setRelation('assistant', $assistant);
            
            return $thread;
        } catch (\Exception $e) {
            Log::error('Błąd podczas tworzenia wątku: ' . $e->getMessage(), [
                'exception' => $e,
                'assistant_id' => $assistant->id,
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Dodaje plik do wątku w OpenAI
     * 
     * @param string $fileId Identyfikator pliku w OpenAI
     * @return string Identyfikator załącznika w wątku
     */
    public function addFile(string $fileId): string
    {
        try {
            $client = \OpenAI::client(config('openai.api_key'));
            
            // Dodaj plik do wątku
            $response = $client->threads()->messages()->files()->create(
                threadId: $this->openai_thread_id,
                parameters: [
                    'file_id' => $fileId,
                ]
            );
            
            return $response->id;
        } catch (\Exception $e) {
            Log::error('Błąd podczas dodawania pliku do wątku: ' . $e->getMessage(), [
                'exception' => $e,
                'thread_id' => $this->id,
                'openai_thread_id' => $this->openai_thread_id,
                'file_id' => $fileId,
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Pobiera listę plików przypisanych do wątku
     * 
     * @return array Lista plików
     */
    public function getFiles(): array
    {
        try {
            $client = \OpenAI::client(config('openai.api_key'));
            
            // Pobierz pliki wątku
            $response = $client->threads()->messages()->files()->list(
                threadId: $this->openai_thread_id
            );
            
            return $response->data;
        } catch (\Exception $e) {
            Log::error('Błąd podczas pobierania plików wątku: ' . $e->getMessage(), [
                'exception' => $e,
                'thread_id' => $this->id,
                'openai_thread_id' => $this->openai_thread_id,
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Usuwa plik z wątku
     * 
     * @param string $fileId Identyfikator pliku w wątku
     * @return bool Czy operacja się powiodła
     */
    public function removeFile(string $fileId): bool
    {
        try {
            $client = \OpenAI::client(config('openai.api_key'));
            
            // Usuń plik z wątku
            $client->threads()->messages()->files()->delete(
                threadId: $this->openai_thread_id,
                fileId: $fileId
            );
            
            return true;
        } catch (\Exception $e) {
            Log::error('Błąd podczas usuwania pliku z wątku: ' . $e->getMessage(), [
                'exception' => $e,
                'thread_id' => $this->id,
                'openai_thread_id' => $this->openai_thread_id,
                'file_id' => $fileId,
            ]);
            
            return false;
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