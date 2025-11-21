<?php

namespace DaSie\Openaiassistant\Models;

use DaSie\Openaiassistant\Enums\CheckmarkStatus;
use DaSie\Openaiassistant\Events\AssistantUpdatedEvent;
use DaSie\Openaiassistant\Jobs\AssistantRequestJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class Message extends Model
{
    protected $guarded = [];
    
    /**
     * Statusy przetwarzania wiadomości
     */
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_COMPLETED_NO_RESPONSE = 'completed_no_response';
    const STATUS_COMPLETED_WITH_ERROR = 'completed_with_error';

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(config('openai-assistant.table.messages'));
    }

    /**
     * Relacja do użytkownika (polimorficzna)
     */
    public function userable()
    {
        return $this->morphTo();
    }

    protected static function booted()
    {
        static::created(function ($message) {
            try {
                // Załaduj relację thread, jeśli nie jest załadowana
                if (!$message->relationLoaded('thread')) {
                    $message->load('thread');
                }
                
                // Utwórz wiadomość w OpenAI
                $message->createInOpenAI();
                
                // Uruchom asystenta
                self::run($message);
            } catch (\Exception $e) {
                Log::error('Błąd podczas tworzenia wiadomości: ' . $e->getMessage(), [
                    'exception' => $e,
                    'message_id' => $message->id,
                    'thread_id' => $message->thread_id ?? null,
                ]);
                
                // Używamy thread->assistant zamiast this->assistant
                if ($message->thread && $message->thread->assistant) {
                    event(new AssistantUpdatedEvent($message->thread->assistant->uuid, ['steps' => ['initialized_ai' => CheckmarkStatus::failed]]));
                }
            }
        });
    }
    
    /**
     * Tworzy wiadomość w OpenAI
     * 
     * @return void
     */
    protected function createInOpenAI(): void
    {
        $client = \OpenAI::client(config('openai.api_key'));
        
        // Przygotuj parametry wiadomości
        $messageParams = $this->prepareMessageParameters();
        
        // Wyślij wiadomość do OpenAI
        $response = $client
            ->threads()
            ->messages()
            ->create(
                threadId: $this->thread->openai_thread_id,
                parameters: $messageParams
            );
        
        // Zapisz identyfikator wiadomości z OpenAI
        $this->openai_message_id = $response->id;
        $this->assistant_id = $this->thread->assistant_id;
        $this->run_status = self::STATUS_PENDING;
        $this->saveQuietly();
    }
    
    /**
     * Przygotowuje parametry wiadomości dla OpenAI
     * 
     * @return array Parametry wiadomości
     */
    protected function prepareMessageParameters(): array
    {
        $messageParams = [
            'role' => 'user',
            'content' => $this->prompt,
        ];
        
        // Dodaj instrukcje formatowania w zależności od typu odpowiedzi
        switch ($this->response_type) {
            case 'html':
                $messageParams['content'] .= ' Format wyjściowy to wyłącznie kod html - zacznij odpowiedź od <p>, zakończ na </p>. Akceptowane tagi: p, span, strong, br. Nie używaj markdownu.';
                break;
            case 'markdown':
                $messageParams['content'] .= ' Format wyjściowy to wyłącznie markdown - zacznij odpowiedź od #, zakończ na #. Akceptowane tagi: #, ##, ###, ####, **, *, __, ~~. Nie używaj html.';
                break;
            case 'json':
                $messageParams['content'] .= ' Format wyjściowy to wyłącznie json.';
                break;
            default:
                $messageParams['content'] .= ' Format wyjściowy to wyłącznie tekst.';
                break;
        }
        
        return $messageParams;
    }

    /**
     * Uruchamia asystenta dla wiadomości z prawdziwym streamingiem OpenAI
     *
     * @param Message $message Wiadomość, dla której uruchamiamy asystenta
     * @return void
     */
    public static function run($message): void
    {
        try {
            // Upewnij się, że relacja thread jest załadowana
            if (!$message->relationLoaded('thread')) {
                $message->load('thread');
            }

            $thread = $message->thread;

            // Zapisz referencję do wiadomości w thread dla handleStreamedRun
            $thread->message = $message;

            ray('Uruchamiam prawdziwy streaming OpenAI');

            // Użyj handleStreamedRun z traitu - prawdziwy streaming z OpenAI API
            $thread->handleStreamedRun(
                threadId: $thread->openai_thread_id,
                assistantId: $thread->assistant->openai_assistant_id,
                onDelta: function ($content) use ($message) {
                    ray('Delta chunk:', ['content' => $content, 'message_id' => $message->id]);
                }
            );

        } catch (\Exception $e) {
            Log::error('Błąd podczas uruchamiania asystenta: ' . $e->getMessage(), [
                'exception' => $e,
                'message_id' => $message->id,
                'thread_id' => $message->thread_id ?? null,
            ]);

            // Aktualizuj status wiadomości
            $message->run_status = self::STATUS_FAILED;
            $message->saveQuietly();

            // Wyślij event o błędzie
            if ($message->thread) {
                event(new AssistantUpdatedEvent($message->thread->uuid, [
                    'steps' => ['processed_ai' => CheckmarkStatus::failed],
                    'completed' => true,
                    'message_id' => $message->id,
                    'error' => $e->getMessage()
                ]));
            }
        }
    }
    
    /**
     * Uruchamia asystenta ze streamingiem
     * 
     * @param callable $streamCallback Callback wywoływany dla każdego fragmentu odpowiedzi
     * @return void
     */
    public function runWithStreaming(callable $streamCallback): void
    {
        // Upewnij się, że relacja thread jest załadowana
        if (!$this->relationLoaded('thread')) {
            $this->load('thread');
        }
        
        // Uruchom streaming w wątku
        $this->thread->runWithStreaming($this, $streamCallback);
    }

    /**
     * Relacja do asystenta
     */
    public function assistant(): BelongsTo
    {
        return $this->belongsTo(Assistant::class);
    }

    /**
     * Relacja do wątku
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }
}
