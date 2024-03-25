<?php

namespace DaSie\Openaiassistant\Models;

use DaSie\Openaiassistant\Enums\CheckmarkStatus;
use DaSie\Openaiassistant\Events\AssistantUpdatedEvent;
use DaSie\Openaiassistant\Jobs\AssistantRequestJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(config('openai-assistant.table.messages'));
    }

    public function userable()
    {
        return $this->morphTo();
    }

    protected static function booted()
    {
        static::created(function ($message) {
            try {
                $client = \OpenAI::client(config('openai.api_key'));
                $message->load('thread');

                $messageParams = [
                    'role' => 'user'
                ];

                switch ($message->response_type) {
                    case 'html':
                        $messageParams['content'] = $message->prompt . ' Format wyjściowy to wyłącznie kod html - zacznij odpowiedź od <p>, zakończ na </p>. Akceptowane tagi: p, span, strong, br. Nie używaj markdownu.';
                        break;
                    case 'markdown':
                        $messageParams['content'] = $message->prompt . ' Format wyjściowy to wyłącznie markdown - zacznij odpowiedź od #, zakończ na #. Akceptowane tagi: #, ##, ###, ####, **, *, __, ~~. Nie używaj html.';
                        break;
                    case 'json':
                        $messageParams['content'] = $message->prompt . ' Format wyjściowy to wyłącznie json.';
                        break;
                    default:
                        $messageParams['content'] = $message->prompt . ' Format wyjściowy to wyłącznie tekst.';
                        break;
                }

                if ($message->thread->files->count() > 0) {
                    $messageParams['file_ids'] = $message->thread->files->pluck('openai_file_id')->toArray();
                    $messageParams['content'] = $messageParams['content'] . ' Odpowiedź oprzyj wyłącznie na załączonych plikach.';
                }

                $response = $client
                    ->threads()
                    ->messages()
                    ->create(
                        threadId: $message->thread->openai_thread_id,
                        parameters: $messageParams);

                $message->openai_message_id = $response->id;
                $message->assistant_id = $message->thread->assistant_id;
                $message->run_status = 'pending';
                $message->saveQuietly();
                self::run($message);
            } catch (\Exception $e) {
                ray($e->getMessage());
                event(new AssistantUpdatedEvent($this->assistant->uuid, ['steps' => ['initialized_ai' => CheckmarkStatus::failed]]));
            }
        });
    }

    public static function run($message): void
    {
        $client = \OpenAI::client(config('openai.api_key'));

        $response = $client
            ->threads()
            ->runs()
            ->create(
                threadId: $message->thread->openai_thread_id,
                parameters: [
                    'assistant_id' => $message->assistant->openai_assistant_id,
                ]
            );
        $message->openai_run_id = $response->id;
        $message->saveQuietly();

        AssistantRequestJob::dispatch($message->id);
    }

    public function assistant(): BelongsTo
    {
        return $this->belongsTo(Assistant::class);
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }
}
