<?php

declare(strict_types=1);

namespace DaSie\Openaiassistant\Jobs;

use DaSie\Openaiassistant\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AssistantStreamJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300; // 5 minutes max

    public function __construct(private int $messageId)
    {
        $this->onQueue(config('openai-assistant.queue', 'default'));
    }

    public function handle(): void
    {
        try {
            $message = Message::with('thread.assistant')->find($this->messageId);

            if (! $message) {
                Log::error('AssistantStreamJob: Message not found', ['message_id' => $this->messageId]);
                return;
            }

            $thread = $message->thread;

            if (! $thread) {
                Log::error('AssistantStreamJob: Thread not found', ['message_id' => $this->messageId]);
                return;
            }

            // Set the message reference on thread for handleStreamedRun callbacks
            $thread->message = $message;

            Log::info('AssistantStreamJob: Starting streaming', [
                'message_id' => $this->messageId,
                'thread_id' => $thread->id,
                'openai_thread_id' => $thread->openai_thread_id,
            ]);

            // Run the streaming
            $thread->handleStreamedRun(
                threadId: $thread->openai_thread_id,
                assistantId: $thread->assistant->openai_assistant_id,
                onDelta: function ($content) use ($message) {
                    Log::debug('AssistantStreamJob: Delta chunk', [
                        'message_id' => $message->id,
                        'chunk_length' => strlen($content),
                    ]);
                }
            );

            Log::info('AssistantStreamJob: Streaming completed', [
                'message_id' => $this->messageId,
            ]);

        } catch (\Exception $e) {
            Log::error('AssistantStreamJob: Error', [
                'message_id' => $this->messageId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
