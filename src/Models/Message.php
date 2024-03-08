<?php

namespace DaSie\Openaiassistant\Models;

use DaSie\Openaiassistant\Enums\CheckmarkStatus;
use DaSie\Openaiassistant\Events\AssistantUpdatedEvent;
use DaSie\Openaiassistant\Events\OpenAiRequestEvent;
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

    protected static function booted()
    {
        static::created(function ($message) {
            try {
                $client = \OpenAI::client(config('openai.api_key'));
                $message->load('thread');

                $messageParams = [
                    'role' => 'user',
                    'content' => $message->prompt
                ];

                if ($message->thread->files) {
                    $messageParams['file_ids'] = $message->thread->files->pluck('openai_file_id')->toArray();
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

        event(new OpenAiRequestEvent($message->id));
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
