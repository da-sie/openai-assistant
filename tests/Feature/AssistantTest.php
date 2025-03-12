<?php

namespace DaSie\Openaiassistant\Tests\Feature;

use DaSie\Openaiassistant\Models\Assistant;
use DaSie\Openaiassistant\Models\Thread;
use DaSie\Openaiassistant\Models\Message;

beforeEach(function () {
    if (empty(config('openai.api_key'))) {
        $this->markTestSkipped('OpenAI API key not configured');
    }
    
    $this->client = \OpenAI::client(config('openai.api_key'));
});

afterEach(function () {
    // Czyszczenie po testach
    foreach ($this->fileIds ?? [] as $fileId) {
        try {
            $this->client->files()->delete($fileId);
        } catch (\Exception $e) {
            // Logowanie błędu jeśli potrzebne
        }
    }

    if (isset($this->vectorStoreId)) {
        try {
            $this->client->vectorStores()->delete($this->vectorStoreId);
        } catch (\Exception $e) {
            // Logowanie błędu jeśli potrzebne
        }
    }
});

/**
 * @group vector
 */
test('can create assistant with vector store and verify response', function () {
    uses()->group('vector');
    
    ray('Test: Rozpoczynam test tworzenia asystenta z vector store');
    
    // Tworzenie asystenta
    ray('Krok 1: Tworzenie asystenta');
    $assistant = Assistant::create([
        'name' => 'Test Assistant',
        'instructions' => 'Jesteś pomocnym asystentem testowym. Używaj narzędzia file_search do przeszukiwania vector store i odpowiadaj na pytania na podstawie znalezionych informacji. Nie dodawaj własnych informacji ani domysłów.',
        'engine' => 'gpt-4-turbo-preview'
    ]);

    ray('Asystent utworzony:', [
        'id' => $assistant->id,
        'openai_assistant_id' => $assistant->openai_assistant_id
    ]);

    expect($assistant->id)->not->toBeNull()
        ->and($assistant->openai_assistant_id)->not->toBeNull();

    // Tworzenie pliku testowego
    ray('Krok 2: Tworzenie pliku testowego');
    $tempFile = tempnam(sys_get_temp_dir(), 'test_') . '.txt';
    file_put_contents($tempFile, "Przemek ma psa o imieniu Piksel. To shih-tzu");
    ray('Plik testowy utworzony:', $tempFile);

    // Przesyłanie pliku do OpenAI
    ray('Krok 3: Przesyłanie pliku do OpenAI');
    $fileResponse = $this->client->files()->upload([
        'purpose' => 'assistants',
        'file' => fopen($tempFile, 'r'),
    ]);

    ray('Plik przesłany do OpenAI:', [
        'file_id' => $fileResponse->id
    ]);

    $this->fileIds[] = $fileResponse->id;
    unlink($tempFile);

    // Czekamy na przetworzenie pliku
    $fileStatus = 'processing';
    $maxAttempts = 10;
    $attempts = 0;

    while ($fileStatus === 'processing' && $attempts < $maxAttempts) {
        $fileInfo = $this->client->files()->retrieve($fileResponse->id);
        $fileStatus = $fileInfo->status;
        
        if ($fileStatus === 'processed') {
            break;
        }
        
        $attempts++;
        sleep(1);
    }

    if ($fileStatus !== 'processed') {
        throw new \Exception('Plik nie został przetworzony w oczekiwanym czasie.');
    }

    // Tworzenie vector store
    ray('Krok 4: Tworzenie vector store');
    $vectorStoreResponse = $this->client->vectorStores()->create([
        'name' => 'Test Vector Store',
        'metadata' => [
            'assistant_id' => $assistant->openai_assistant_id
        ]
    ]);

    ray('Vector store utworzony:', [
        'id' => $vectorStoreResponse->id
    ]);

    $this->vectorStoreId = $vectorStoreResponse->id;

    // Dodawanie pliku do vector store
    ray('Krok 5: Dodawanie pliku do vector store');
    $this->client->vectorStores()->files()->create($this->vectorStoreId, [
        'file_id' => $this->fileIds[0],
    ]);
    ray('Plik dodany do vector store');

    // Czekamy na zaindeksowanie pliku
    ray('Krok 5.1: Czekamy na zaindeksowanie pliku');
    sleep(2);

    // Sprawdzamy status vector store
    ray('Krok 5.2: Sprawdzamy status vector store');
    $vectorStoreStatus = $this->client->vectorStores()->retrieve($this->vectorStoreId);
    ray('Status vector store:', [
        'id' => $vectorStoreStatus->id,
        'status' => $vectorStoreStatus->status,
        'file_count' => count($vectorStoreStatus->file_ids ?? [])
    ]);

    // Sprawdzamy status pliku w vector store
    ray('Krok 5.3: Sprawdzamy status pliku w vector store');
    $fileStatus = $this->client->vectorStores()->files()->list($this->vectorStoreId);
    ray('Status pliku w vector store:', [
        'files' => $fileStatus->data
    ]);

    // Czekamy na zaindeksowanie pliku
    ray('Krok 5.4: Czekamy na zaindeksowanie pliku');
    $maxAttempts = 30;
    $attempts = 0;
    $isIndexed = false;

    while (!$isIndexed && $attempts < $maxAttempts) {
        $fileStatus = $this->client->vectorStores()->files()->list($this->vectorStoreId);
        $file = $fileStatus->data[0] ?? null;
        
        if ($file && $file->status === 'completed') {
            $isIndexed = true;
            ray('Plik został zaindeksowany');
            break;
        }
        
        ray('Czekamy na zaindeksowanie pliku, status:', [
            'status' => $file->status ?? 'unknown',
            'attempt' => $attempts + 1
        ]);
        
        sleep(2);
        $attempts++;
    }

    if (!$isIndexed) {
        ray('Nie udało się zaindeksować pliku po ' . $maxAttempts . ' próbach');
    }

    ray('Zakończono czekanie na zaindeksowanie');

    // Powiązanie vector store z asystentem
    ray('Krok 6: Powiązanie vector store z asystentem');
    $result = $assistant->linkVectorStore($this->vectorStoreId);
    ray('Vector store powiązany z asystentem:', [
        'success' => $result
    ]);

    // Sprawdzamy status asystenta
    ray('Krok 6.1: Sprawdzamy status asystenta');
    $assistantStatus = $this->client->assistants()->retrieve($assistant->openai_assistant_id);
    ray('Status asystenta:', [
        'id' => $assistantStatus->id,
        'tools' => $assistantStatus->tools
    ]);

    // Tworzenie wątku i wiadomości
    ray('Krok 7: Tworzenie wątku');
    $thread = Thread::create([
        'assistant_id' => $assistant->id,
        'uuid' => uniqid('test_'),
        'model_id' => 1,
        'model_type' => 'Test'
    ]);

    ray('Wątek utworzony:', [
        'id' => $thread->id,
        'openai_thread_id' => $thread->openai_thread_id
    ]);

    ray('Krok 8: Tworzenie wiadomości');
    $message = $thread->createMessage([
        'prompt' => 'Jakie zwierzę ma Przemek i jak się nazywa?',
        'response_type' => 'text'
    ]);

    ray('Wiadomość utworzona:', [
        'id' => $message->id,
        'prompt' => $message->prompt
    ]);

    // Uruchamianie asystenta i oczekiwanie na odpowiedź
    ray('Krok 9: Uruchamianie asystenta i oczekiwanie na odpowiedź');
    $response = $thread->run($message);

    ray('Odpowiedź otrzymana:', [
        'response' => $response->response,
        'run_status' => $response->run_status
    ]);

    // Weryfikacja odpowiedzi
    ray('Krok 10: Weryfikacja odpowiedzi');
    expect($response->response)->toContain('Piksel')
        ->and($response->response)->toContain('shih-tzu');
    
    ray('Test zakończony');
});

test('can handle multiple files in vector store', function () {
    // Tworzenie asystenta
    $assistant = Assistant::create([
        'name' => 'Multi-File Test Assistant',
        'instructions' => 'Jesteś pomocnym asystentem testowym. Używaj narzędzia file_search do przeszukiwania vector store i odpowiadaj na pytania na podstawie znalezionych informacji. Nie dodawaj własnych informacji ani domysłów.',
        'engine' => 'gpt-4-turbo-preview'
    ]);

    // Przygotowanie wielu plików
    $files = [
        'pies.txt' => "Przemek ma psa o imieniu Piksel. To shih-tzu",
        'kot.txt' => "Przemek ma też kota o imieniu Luna. To kot brytyjski."
    ];

    foreach ($files as $filename => $content) {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_') . '.txt';
        file_put_contents($tempFile, $content);

        $fileResponse = $this->client->files()->upload([
            'purpose' => 'assistants',
            'file' => fopen($tempFile, 'r'),
        ]);

        $this->fileIds[] = $fileResponse->id;
        unlink($tempFile);
    }

    // Tworzenie vector store
    $vectorStoreResponse = $this->client->vectorStores()->create([
        'name' => 'Multi-File Test Vector Store',
        'metadata' => [
            'assistant_id' => $assistant->openai_assistant_id
        ]
    ]);

    $this->vectorStoreId = $vectorStoreResponse->id;

    // Dodawanie plików do vector store
    foreach ($this->fileIds as $fileId) {
        $this->client->vectorStores()->files()->create($this->vectorStoreId, [
            'file_id' => $fileId,
        ]);
    }

    // Czekamy na zaindeksowanie plików
    ray('Czekamy na zaindeksowanie plików');
    $maxAttempts = 30;
    $attempts = 0;
    $allIndexed = true;

    while (!$allIndexed && $attempts < $maxAttempts) {
        $fileStatus = $this->client->vectorStores()->files()->list($this->vectorStoreId);
        $allIndexed = true;
        
        foreach ($fileStatus->data as $file) {
            if ($file->status !== 'completed') {
                $allIndexed = false;
                break;
            }
        }
        
        if ($allIndexed) {
            ray('Wszystkie pliki zostały zaindeksowane');
            break;
        }
        
        ray('Czekamy na zaindeksowanie plików, status:', [
            'files' => array_map(fn($file) => [
                'id' => $file->id,
                'status' => $file->status
            ], $fileStatus->data),
            'attempt' => $attempts + 1
        ]);
        
        sleep(2);
        $attempts++;
    }

    if (!$allIndexed) {
        ray('Nie udało się zaindeksować wszystkich plików po ' . $maxAttempts . ' próbach');
    }

    ray('Zakończono czekanie na zaindeksowanie');

    // Powiązanie vector store z asystentem
    $assistant->linkVectorStore($this->vectorStoreId);

    // Test wyszukiwania informacji
    $thread = Thread::create([
        'assistant_id' => $assistant->id,
        'uuid' => uniqid('test_'),
        'model_id' => 1,
        'model_type' => 'Test'
    ]);

    $message = $thread->createMessage([
        'prompt' => 'Jakie zwierzęta ma Przemek?',
        'response_type' => 'text'
    ]);

    $response = $thread->run($message);

    // Weryfikacja odpowiedzi
    expect($response->response)->toContain('Piksel')
        ->and($response->response)->toContain('Luna');
});

test('can handle files in thread', function () {
    // Tworzenie asystenta
    $assistant = Assistant::create([
        'name' => 'Thread Files Test Assistant',
        'instructions' => 'Jesteś pomocnym asystentem testowym. Używaj narzędzia file_search do przeszukiwania plików i odpowiadaj na pytania na podstawie znalezionych informacji.',
        'engine' => 'gpt-4-turbo-preview',
        'tools' => [
            ['type' => 'file_search']
        ]
    ]);

    ray('Asystent utworzony:', [
        'id' => $assistant->id,
        'openai_assistant_id' => $assistant->openai_assistant_id,
        'tools' => $assistant->tools
    ]);

    // Sprawdź konfigurację asystenta w OpenAI
    $client = \OpenAI::client(config('openai.api_key'));
    $openaiAssistant = $client->assistants()->retrieve($assistant->openai_assistant_id);

    ray('Konfiguracja asystenta w OpenAI:', [
        'id' => $openaiAssistant->id,
        'tools' => $openaiAssistant->tools,
        'file_ids' => $openaiAssistant->file_ids ?? []
    ]);

    // Tworzenie pliku testowego
    $tempFile = tempnam(sys_get_temp_dir(), 'test_') . '.txt';
    file_put_contents($tempFile, "To jest dokument testowy do wątku.");

    ray('Plik testowy utworzony:', [
        'path' => $tempFile,
        'content' => file_get_contents($tempFile)
    ]);

    // Przesyłanie pliku do OpenAI
    $fileResponse = $this->client->files()->upload([
        'purpose' => 'assistants',
        'file' => fopen($tempFile, 'r'),
    ]);

    $this->fileIds[] = $fileResponse->id;
    unlink($tempFile);

    // Czekamy na przetworzenie pliku
    $fileStatus = 'processing';
    $maxAttempts = 10;
    $attempts = 0;

    while ($fileStatus === 'processing' && $attempts < $maxAttempts) {
        $fileInfo = $this->client->files()->retrieve($fileResponse->id);
        $fileStatus = $fileInfo->status;
        
        if ($fileStatus === 'processed') {
            break;
        }
        
        $attempts++;
        sleep(1);
    }

    if ($fileStatus !== 'processed') {
        throw new \Exception('Plik nie został przetworzony w oczekiwanym czasie.');
    }

    // Tworzenie wątku
    $thread = Thread::create([
        'assistant_id' => $assistant->id,
        'uuid' => uniqid('test_'),
        'model_id' => 1,
        'model_type' => 'Test'
    ]);

    // Dodawanie pliku do wątku
    $thread->attachFile($this->fileIds[0]);

    // Test wiadomości z odwołaniem do pliku
    $message = $thread->createMessage([
        'prompt' => 'Co znajduje się w załączonym dokumencie?',
        'response_type' => 'text'
    ]);

    $response = $thread->run($message);

    // Weryfikacja odpowiedzi
    expect($response->response)->toContain('dokument testowy');
});