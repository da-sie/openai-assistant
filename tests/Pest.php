<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

use DaSie\Openaiassistant\Tests\TestCase;

uses(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function getOpenAIClient() {
    $apiKey = env('OPENAI_API_KEY');
    if (empty($apiKey)) {
        throw new Exception('OpenAI API key not configured in .env.testing');
    }
    return OpenAI\Client::factory()
        ->withApiKey($apiKey)
        ->make();
}

use DaSie\Openaiassistant\Models\Assistant;
use DaSie\Openaiassistant\Models\Thread;
use DaSie\Openaiassistant\Models\Message;

beforeEach(function () {
    $this->client = getOpenAIClient();
});

test('can create assistant with vector store and verify response', function () {
    // Debugowanie - sprawdź czy klucz API jest poprawnie ustawiony
    ray(env('OPENAI_API_KEY'));

    // Najpierw stwórzmy asystenta bezpośrednio w OpenAI
    $openaiAssistant = $this->client->assistants()->create([
        'name' => 'Test Assistant',
        'instructions' => 'Jesteś pomocnym asystentem testowym.',
        'model' => 'gpt-4-turbo-preview',
        'tools' => [
            ['type' => 'retrieval']
        ]
    ]);

    // Teraz stwórzmy asystenta w naszej bazie
    $assistant = new Assistant([
        'name' => 'Test Assistant',
        'instructions' => 'Jesteś pomocnym asystentem testowym.',
        'engine' => 'gpt-4-turbo-preview',
    ]);
    
    // Ręcznie ustawiamy openai_assistant_id przed zapisem
    $assistant->openai_assistant_id = $openaiAssistant->id;
    $assistant->save();

    // Sprawdzamy czy asystent został utworzony poprawnie
    expect($assistant->id)->not->toBeNull()
        ->and($assistant->openai_assistant_id)->toBe($openaiAssistant->id);

    // Tworzenie pliku testowego
    $tempFile = tempnam(sys_get_temp_dir(), 'test_');
    file_put_contents($tempFile, "Przemek ma psa o imieniu Piksel. To shih-tzu");

    try {
        // Przesyłanie pliku do OpenAI
        $fileResponse = $this->client->files()->upload([
            'purpose' => 'assistants',
            'file' => fopen($tempFile, 'r'),
        ]);

        $this->fileIds[] = $fileResponse->id;
        
        // Tworzenie vector store
        $vectorStoreResponse = $this->client->vectorStores()->create([
            'name' => 'Test Vector Store',
            'metadata' => [
                'assistant_id' => $assistant->openai_assistant_id
            ]
        ]);

        $this->vectorStoreId = $vectorStoreResponse->id;

        // Dodawanie pliku do vector store
        $this->client->vectorStores()->files()->create($this->vectorStoreId, [
            'file_id' => $fileResponse->id,
        ]);

        // Powiązanie vector store z asystentem
        $result = $assistant->linkVectorStore($this->vectorStoreId);
        expect($result)->toBeTrue();

        // Tworzenie wątku i wiadomości
        $thread = Thread::create([
            'assistant_id' => $assistant->id,
            'uuid' => uniqid('test_'),
            'model_id' => 1,
            'model_type' => 'Test'
        ]);

        $message = $thread->createMessage([
            'prompt' => 'Jakie zwierzę ma Przemek i jak się nazywa?',
            'response_type' => 'text'
        ]);

        // Uruchamianie asystenta i oczekiwanie na odpowiedź
        $response = $thread->run($message);

        // Weryfikacja odpowiedzi
        expect($response->response)
            ->toContain('Piksel')
            ->toContain('shih-tzu');

    } finally {
        // Czyszczenie
        unlink($tempFile);
        
        // Czyszczenie w OpenAI
        if (isset($fileResponse)) {
            $this->client->files()->delete($fileResponse->id);
        }
        if (isset($vectorStoreResponse)) {
            $this->client->vectorStores()->delete($vectorStoreResponse->id);
        }
        if (isset($openaiAssistant)) {
            $this->client->assistants()->delete($openaiAssistant->id);
        }
    }
}); 