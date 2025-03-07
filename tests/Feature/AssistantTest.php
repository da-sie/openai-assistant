<?php

use DaSie\Openaiassistant\Models\Assistant;
use DaSie\Openaiassistant\Models\Thread;
use DaSie\Openaiassistant\Models\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Mockujemy klienta OpenAI, aby nie wykonywać rzeczywistych zapytań
    Http::fake([
        'api.openai.com/v1/assistants/*' => Http::response([
            'id' => 'asst_test123',
            'object' => 'assistant',
            'created_at' => time(),
            'name' => 'Test Assistant',
            'description' => null,
            'model' => 'gpt-3.5-turbo',
            'instructions' => 'You are a helpful assistant',
            'tools' => [
                [
                    'type' => 'retrieval',
                    'retrieval_tool_config' => [
                        'vector_store_ids' => ['vs_test123']
                    ]
                ]
            ],
            'file_ids' => ['file-test123'],
        ], 200),
        'api.openai.com/v1/files' => Http::response([
            'id' => 'file-test123',
            'object' => 'file',
            'created_at' => time(),
            'filename' => 'test.pdf',
            'purpose' => 'assistants',
            'bytes' => 1024,
            'status' => 'processed',
        ], 200),
        'api.openai.com/v1/files/*' => Http::response(null, 204),
        'api.openai.com/v1/vector_stores' => Http::response([
            'id' => 'vs_test123',
            'object' => 'vector_store',
            'created_at' => time(),
            'name' => 'Test Vector Store',
        ], 200),
        'api.openai.com/v1/vector_stores/*' => Http::response([
            'id' => 'vs_test123',
            'object' => 'vector_store',
            'created_at' => time(),
            'name' => 'Test Vector Store',
        ], 200),
        'api.openai.com/v1/vector_stores/*/files' => Http::response([
            'object' => 'list',
            'data' => [
                [
                    'id' => 'file-test123',
                    'object' => 'vector_store.file',
                    'created_at' => time(),
                ]
            ]
        ], 200),
        'api.openai.com/v1/threads' => Http::response([
            'id' => 'thread_test123',
            'object' => 'thread',
            'created_at' => time(),
        ], 200),
        'api.openai.com/v1/threads/*/runs' => Http::response([
            'id' => 'run_test123',
            'object' => 'thread.run',
            'created_at' => time(),
            'thread_id' => 'thread_test123',
            'assistant_id' => 'asst_test123',
            'status' => 'completed',
        ], 200),
        'api.openai.com/v1/threads/*/messages' => Http::response([
            'data' => [
                [
                    'id' => 'msg_test123',
                    'object' => 'thread.message',
                    'created_at' => time(),
                    'thread_id' => 'thread_test123',
                    'role' => 'assistant',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => [
                                'value' => 'Test response',
                                'annotations' => []
                            ]
                        ]
                    ],
                ]
            ]
        ], 200),
    ]);
});

test('assistant has correct relations', function () {
    $assistant = new Assistant();
    
    expect($assistant->messages())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class)
        ->and($assistant->threads())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class)
        ->and($assistant->files())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('resetFiles resets vector store and files', function () {
    // Tworzymy mock dla Assistant
    $assistant = Mockery::mock(Assistant::class)->makePartial();
    $assistant->id = 1;
    $assistant->openai_assistant_id = 'asst_test123';
    $assistant->vector_store_id = 'vs_test123';
    
    // Mockujemy metody
    $assistant->shouldReceive('resetVectorStore')->once()->andReturn(true);
    $assistant->shouldReceive('deleteAllFiles')->once()->andReturn(1);
    $assistant->shouldReceive('saveQuietly')->andReturn(true);
    
    // Wywołujemy metodę
    $result = $assistant->resetFiles();
    
    // Sprawdzamy, czy metoda zwróciła instancję asystenta
    expect($result)->toBe($assistant);
});

test('resetVectorStore deletes vector store', function () {
    // Tworzymy mock dla Assistant
    $assistant = Mockery::mock(Assistant::class)->makePartial();
    $assistant->id = 1;
    $assistant->openai_assistant_id = 'asst_test123';
    $assistant->vector_store_id = 'vs_test123';
    $assistant->shouldReceive('saveQuietly')->andReturn(true);
    
    // Wywołujemy metodę
    $result = $assistant->resetVectorStore();
    
    // Sprawdzamy, czy vector store został usunięty
    expect($result)->toBeTrue()
        ->and($assistant->vector_store_id)->toBeNull();
});

test('uploadFiles uploads files to OpenAI', function () {
    // Tworzymy mock dla Assistant
    $assistant = Mockery::mock(Assistant::class)->makePartial();
    
    // Mockujemy funkcję is_file, aby zawsze zwracała true
    $assistant->shouldReceive('is_file')->andReturn(true);
    
    // Mockujemy funkcję fopen, aby nie próbować otwierać rzeczywistych plików
    $assistant->shouldReceive('fopen')->andReturn(fopen('php://memory', 'r'));
    
    // Wywołujemy metodę
    $result = $assistant->uploadFiles(['/path/to/test.pdf']);
    
    // Sprawdzamy, czy pliki zostały przesłane
    expect($result)
        ->toBeArray()
        ->toHaveKeys(['uploaded_files', 'errors'])
        ->and($result['uploaded_files'])->toContain('file-test123')
        ->and($result['errors'])->toBeEmpty();
});

test('createAndLinkVectorStore creates and links vector store', function () {
    // Tworzymy mock dla Assistant
    $assistant = Mockery::mock(Assistant::class)->makePartial();
    $assistant->id = 1;
    $assistant->openai_assistant_id = 'asst_test123';
    $assistant->name = 'Test Assistant';
    
    // Mockujemy metody
    $assistant->shouldReceive('resetVectorStore')->once()->andReturn(true);
    $assistant->shouldReceive('saveQuietly')->andReturn(true);
    
    // Wywołujemy metodę
    $result = $assistant->createAndLinkVectorStore(['file-test123']);
    
    // Sprawdzamy, czy vector store został utworzony i powiązany
    expect($result)
        ->toBeArray()
        ->toHaveKeys(['success', 'vector_store_id', 'files'])
        ->and($result['success'])->toBeTrue()
        ->and($result['vector_store_id'])->toBe('vs_test123')
        ->and($result['files'])->toBeArray();
});

test('updateKnowledge updates assistant knowledge', function () {
    // Tworzymy mock dla Assistant
    $assistant = Mockery::mock(Assistant::class)->makePartial();
    $assistant->id = 1;
    $assistant->openai_assistant_id = 'asst_test123';
    
    // Mockujemy metody
    $assistant->shouldReceive('uploadFiles')->once()->andReturn([
        'uploaded_files' => ['file-test123'],
        'errors' => [],
    ]);
    
    $assistant->shouldReceive('createAndLinkVectorStore')->once()->andReturn([
        'success' => true,
        'vector_store_id' => 'vs_test123',
        'files' => [new File()],
    ]);
    
    // Wywołujemy metodę
    $result = $assistant->updateKnowledge(['/path/to/test.pdf']);
    
    // Sprawdzamy, czy wiedza została zaktualizowana
    expect($result)
        ->toBeArray()
        ->toHaveKeys(['success', 'message', 'files_added', 'vector_store_id', 'errors'])
        ->and($result['success'])->toBeTrue()
        ->and($result['vector_store_id'])->toBe('vs_test123')
        ->and($result['files_added'])->toBe(1);
});

test('linkVectorStore links vector store to assistant', function () {
    // Tworzymy mock dla Assistant
    $assistant = Mockery::mock(Assistant::class)->makePartial();
    $assistant->id = 1;
    $assistant->openai_assistant_id = 'asst_test123';
    $assistant->shouldReceive('saveQuietly')->andReturn(true);
    
    // Wywołujemy metodę
    $result = $assistant->linkVectorStore('vs_test123');
    
    // Sprawdzamy, czy vector store został powiązany
    expect($result)->toBeTrue()
        ->and($assistant->vector_store_id)->toBe('vs_test123');
});

test('linkMultipleVectorStores links multiple vector stores', function () {
    // Tworzymy mock dla Assistant
    $assistant = Mockery::mock(Assistant::class)->makePartial();
    $assistant->id = 1;
    $assistant->openai_assistant_id = 'asst_test123';
    
    // Mockujemy metody
    $assistant->shouldReceive('validateVectorStoreIds')->once()->andReturn(['vs_test123', 'vs_test456']);
    $assistant->shouldReceive('saveQuietly')->andReturn(true);
    
    // Wywołujemy metodę
    $result = $assistant->linkMultipleVectorStores(['vs_test123', 'vs_test456']);
    
    // Sprawdzamy, czy vector stores zostały powiązane
    expect($result)->toBeTrue()
        ->and($assistant->vector_store_id)->toBe('vs_test123');
});

test('searchVectorStore searches vector store', function () {
    // Tworzymy mock dla Assistant
    $assistant = Mockery::mock(Assistant::class)->makePartial();
    $assistant->id = 1;
    $assistant->openai_assistant_id = 'asst_test123';
    $assistant->vector_store_id = 'vs_test123';
    
    // Mockujemy metody
    $assistant->shouldReceive('searchVectorStoreWithAssistant')->once()->andReturn([
        [
            'id' => 'msg_test123',
            'content' => [
                [
                    'type' => 'text',
                    'text' => [
                        'value' => 'Test response',
                    ]
                ]
            ],
        ]
    ]);
    
    // Wywołujemy metodę
    $result = $assistant->searchVectorStore('test query');
    
    // Sprawdzamy, czy wyszukiwanie zwróciło wyniki
    expect($result)
        ->toBeArray()
        ->toHaveKeys(['query', 'vector_store_id', 'results'])
        ->and($result['query'])->toBe('test query')
        ->and($result['vector_store_id'])->toBe('vs_test123')
        ->and($result['results'])->toBeArray();
});

test('checkVectorStoreStatus returns vector store status', function () {
    // Tworzymy mock dla Assistant
    $assistant = Mockery::mock(Assistant::class)->makePartial();
    $assistant->id = 1;
    $assistant->openai_assistant_id = 'asst_test123';
    $assistant->vector_store_id = 'vs_test123';
    
    // Wywołujemy metodę
    $result = $assistant->checkVectorStoreStatus();
    
    // Sprawdzamy, czy status został zwrócony
    expect($result)
        ->toBeArray()
        ->toHaveKeys(['status', 'has_retrieval_tool', 'has_vector_store_id', 'is_linked', 'vector_store_id', 'linked_vector_store_ids', 'vector_store'])
        ->and($result['status'])->toBe('success')
        ->and($result['has_retrieval_tool'])->toBeTrue()
        ->and($result['has_vector_store_id'])->toBeTrue()
        ->and($result['is_linked'])->toBeTrue()
        ->and($result['vector_store_id'])->toBe('vs_test123');
}); 