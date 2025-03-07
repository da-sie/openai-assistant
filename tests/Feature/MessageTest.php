<?php

use DaSie\Openaiassistant\Models\Message;
use DaSie\Openaiassistant\Models\Thread;
use DaSie\Openaiassistant\Models\Assistant;
use DaSie\Openaiassistant\Jobs\AssistantRequestJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // Mockujemy klienta OpenAI, aby nie wykonywać rzeczywistych zapytań
    Http::fake([
        'api.openai.com/v1/threads/*/messages' => Http::response([
            'id' => 'msg_test123',
            'object' => 'thread.message',
            'created_at' => time(),
            'thread_id' => 'thread_test123',
            'role' => 'user',
            'content' => [
                [
                    'type' => 'text',
                    'text' => [
                        'value' => 'Test message',
                        'annotations' => []
                    ]
                ]
            ],
        ], 200),
        'api.openai.com/v1/threads/*/runs' => Http::response([
            'id' => 'run_test123',
            'object' => 'thread.run',
            'created_at' => time(),
            'thread_id' => 'thread_test123',
            'assistant_id' => 'asst_test123',
            'status' => 'queued',
        ], 200),
    ]);
    
    // Mockujemy kolejkę, aby nie wykonywać rzeczywistych zadań
    Queue::fake();
});

test('message has correct relations', function () {
    $message = new Message();
    
    expect($message->assistant())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class)
        ->and($message->thread())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class)
        ->and($message->userable())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\MorphTo::class);
});

test('message has correct status constants', function () {
    expect(Message::STATUS_PENDING)->toBe('pending')
        ->and(Message::STATUS_PROCESSING)->toBe('processing')
        ->and(Message::STATUS_COMPLETED)->toBe('completed')
        ->and(Message::STATUS_FAILED)->toBe('failed')
        ->and(Message::STATUS_COMPLETED_NO_RESPONSE)->toBe('completed_no_response')
        ->and(Message::STATUS_COMPLETED_WITH_ERROR)->toBe('completed_with_error');
});

test('createInOpenAI creates message in OpenAI', function () {
    // Tworzymy mocki dla Thread i Assistant
    $assistant = Mockery::mock(Assistant::class)->makePartial();
    $assistant->id = 1;
    $assistant->openai_assistant_id = 'asst_test123';
    
    $thread = Mockery::mock(Thread::class)->makePartial();
    $thread->id = 1;
    $thread->assistant_id = 1;
    $thread->openai_thread_id = 'thread_test123';
    $thread->shouldReceive('relationLoaded')->with('files')->andReturn(true);
    $thread->files = collect([]);
    $thread->assistant = $assistant;
    
    // Tworzymy wiadomość
    $message = Mockery::mock(Message::class)->makePartial();
    $message->thread_id = 1;
    $message->prompt = 'Test message';
    $message->response_type = 'text';
    $message->shouldReceive('saveQuietly')->once()->andReturn(true);
    $message->thread = $thread;
    
    // Wywołujemy metodę
    $message->createInOpenAI();
    
    // Sprawdzamy, czy wiadomość została zaktualizowana
    expect($message->openai_message_id)->toBe('msg_test123')
        ->and($message->assistant_id)->toBe(1)
        ->and($message->run_status)->toBe(Message::STATUS_PENDING);
});

test('prepareMessageParameters returns correct parameters', function () {
    // Tworzymy wiadomość
    $message = new Message([
        'prompt' => 'Test message',
        'response_type' => 'text',
    ]);
    
    // Tworzymy mock dla Thread
    $thread = Mockery::mock(Thread::class)->makePartial();
    $thread->shouldReceive('relationLoaded')->with('files')->andReturn(true);
    $thread->files = collect([]);
    $message->thread = $thread;
    
    // Wywołujemy metodę
    $parameters = $message->prepareMessageParameters();
    
    // Sprawdzamy parametry
    expect($parameters)
        ->toBeArray()
        ->toHaveKeys(['role', 'content'])
        ->and($parameters['role'])->toBe('user')
        ->and($parameters['content'])->toContain('Test message')
        ->and($parameters['content'])->toContain('Format wyjściowy to wyłącznie tekst');
});

test('run dispatches job', function () {
    // Tworzymy mocki dla Thread i Assistant
    $assistant = Mockery::mock(Assistant::class)->makePartial();
    $assistant->openai_assistant_id = 'asst_test123';
    
    $thread = Mockery::mock(Thread::class)->makePartial();
    $thread->openai_thread_id = 'thread_test123';
    $thread->shouldReceive('relationLoaded')->with('assistant')->andReturn(true);
    $thread->assistant = $assistant;
    
    // Tworzymy wiadomość
    $message = Mockery::mock(Message::class)->makePartial();
    $message->id = 1;
    $message->thread_id = 1;
    $message->shouldReceive('relationLoaded')->with('thread')->andReturn(true);
    $message->shouldReceive('saveQuietly')->once()->andReturn(true);
    $message->thread = $thread;
    
    // Wywołujemy metodę
    Message::run($message);
    
    // Sprawdzamy, czy zadanie zostało dodane do kolejki
    Queue::assertPushed(AssistantRequestJob::class, function ($job) use ($message) {
        return $job->messageId === $message->id;
    });
    
    // Sprawdzamy, czy wiadomość została zaktualizowana
    expect($message->openai_run_id)->toBe('run_test123')
        ->and($message->run_status)->toBe(Message::STATUS_PROCESSING);
});

test('runWithStreaming delegates to thread', function () {
    // Tworzymy mock dla Thread
    $thread = Mockery::mock(Thread::class)->makePartial();
    $thread->shouldReceive('runWithStreaming')->once()->with(Mockery::type(Message::class), Mockery::type('callable'));
    
    // Tworzymy wiadomość
    $message = Mockery::mock(Message::class)->makePartial();
    $message->shouldReceive('relationLoaded')->with('thread')->andReturn(true);
    $message->thread = $thread;
    
    // Tworzymy callback
    $callback = function () {};
    
    // Wywołujemy metodę
    $message->runWithStreaming($callback);
}); 