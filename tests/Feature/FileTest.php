<?php

use DaSie\Openaiassistant\Models\File;
use DaSie\Openaiassistant\Models\Assistant;
use DaSie\Openaiassistant\Models\Thread;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Mockujemy klienta OpenAI, aby nie wykonywać rzeczywistych zapytań
    Http::fake([
        'api.openai.com/v1/files/*' => Http::response([
            'id' => 'file-test123',
            'filename' => 'test.pdf',
            'purpose' => 'assistants',
            'created_at' => time(),
            'bytes' => 1024,
            'status' => 'processed',
        ], 200),
    ]);
});

test('file has correct relations', function () {
    $file = new File();
    
    expect($file->assistant())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class)
        ->and($file->thread())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('getDetails returns file details from OpenAI', function () {
    $file = new File([
        'openai_file_id' => 'file-test123',
    ]);
    
    $details = $file->getDetails();
    
    expect($details)
        ->toBeArray()
        ->toHaveKeys(['id', 'name', 'purpose', 'created_at', 'bytes', 'status'])
        ->and($details['id'])->toBe('file-test123');
});

test('getDetails handles errors gracefully', function () {
    Http::fake([
        'api.openai.com/v1/files/*' => Http::response(['error' => 'File not found'], 404),
    ]);
    
    $file = new File([
        'openai_file_id' => 'file-nonexistent',
    ]);
    
    $details = $file->getDetails();
    
    expect($details)->toBeNull();
});

test('deleteFromOpenAI deletes file from OpenAI', function () {
    Http::fake([
        'api.openai.com/v1/files/*' => Http::response(null, 204),
    ]);
    
    $file = new File([
        'openai_file_id' => 'file-test123',
    ]);
    
    $result = $file->deleteFromOpenAI();
    
    expect($result)->toBeTrue();
});

test('deleteFromOpenAI handles errors gracefully', function () {
    Http::fake([
        'api.openai.com/v1/files/*' => Http::response(['error' => 'File not found'], 404),
    ]);
    
    $file = new File([
        'openai_file_id' => 'file-nonexistent',
    ]);
    
    $result = $file->deleteFromOpenAI();
    
    expect($result)->toBeFalse();
});

test('deleteWithOpenAI deletes file from OpenAI and database', function () {
    Http::fake([
        'api.openai.com/v1/files/*' => Http::response(null, 204),
    ]);
    
    // Tworzymy instancję File i mockujemy metodę delete
    $file = Mockery::mock(File::class)->makePartial();
    $file->shouldReceive('deleteFromOpenAI')->once()->andReturn(true);
    $file->shouldReceive('delete')->once()->andReturn(true);
    
    $result = $file->deleteWithOpenAI();
    
    expect($result)->toBeTrue();
}); 