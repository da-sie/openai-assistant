<?php

namespace DaSie\Openaiassistant\Models;
;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

class Assistant extends Model
{
    protected $fillable = [
        'openai_assistant_id',
        'name',
        'instructions',
        'engine',
        'vector_store_id',
        'tools',
    ];

    protected $casts = [
        'tools' => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(config('openai-assistant.table.assistants'));
    }

    protected static function booted()
    {
        static::updated(function ($assistant) {
            try {
                $client = \OpenAI::client(config('openai.api_key'));

                // Sprawdzamy, czy openai_assistant_id istnieje
                if (!empty($assistant->openai_assistant_id)) {
                    $client->assistants()->modify($assistant->openai_assistant_id, [
                        'name' => $assistant->name,
                        'instructions' => $assistant->instructions,
                        'model' => $assistant->engine,
                        'tools' => $assistant->tools ?? [['type' => 'file_search']],
                    ]);
                    // event(new AssistantUpdatedEvent($assistant->id, ['steps' => ['initialized_ai' => CheckmarkStatus::success]]));
                } else {
                    Log::warning('Próba aktualizacji asystenta bez openai_assistant_id');
                }
            } catch (\Exception $e) {
                ray($e->getMessage());
                Log::error($e->getMessage());
            }
        });

        static::deleted(function ($assistant) {
            try {
                $client = \OpenAI::client(config('openai.api_key'));

                // Sprawdzamy, czy openai_assistant_id istnieje
                if (!empty($assistant->openai_assistant_id)) {
                    $client->assistants()->delete($assistant->openai_assistant_id);
                } else {
                    Log::warning('Próba usunięcia asystenta bez openai_assistant_id');
                }
            } catch (\Exception $e) {
                ray($e->getMessage());
                Log::error($e->getMessage());
            }
        });

        static::created(function ($assistant) {
            try {
                $client = \OpenAI::client(config('openai.api_key'));
                $assistantModel = $client->assistants()->create([
                    'instructions' => $assistant->instructions,
                    'name' => $assistant->name,
                    'tools' => [
                        [
                            'type' => 'file_search',
                        ],
                    ],
                    'model' => $assistant->engine,
                ]);

                $assistant->openai_assistant_id = $assistantModel->id;
                $assistant->saveQuietly();
                // event(new AssistantUpdatedEvent($assistant->id, ['steps' => ['initialized_ai' => CheckmarkStatus::success]]));
            } catch (\Exception $e) {
                Log::error('Błąd podczas tworzenia asystenta w OpenAI: ' . $e->getMessage());
                Log::error($e->getTraceAsString());
                throw $e;
            }
        });
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }

    /**
     * Resetuje wiedzę asystenta poprzez usunięcie wszystkich jego plików.
     *
     * @return self
     */
    public function resetFiles(): self
    {
        try {
            $client = \OpenAI::client(config('openai.api_key'));

            // Sprawdzamy, czy openai_assistant_id istnieje
            if (empty($this->openai_assistant_id)) {
                Log::warning('Próba resetowania plików asystenta bez openai_assistant_id');
                return $this;
            }

            // Resetuj vector store
            $this->resetVectorStore();

            // Modyfikujemy asystenta, ustawiając pustą tablicę file_ids i aktualizując narzędzia
            $client->assistants()->modify($this->openai_assistant_id, [
                'tools' => [
                    [
                        'type' => 'file_search',
                    ],
                ],
                'file_ids' => [],
            ]);

            // Usuń pliki z OpenAI i z bazy danych
            $this->deleteAllFiles();

            return $this;
        } catch (\Exception $e) {
            Log::error('Błąd podczas resetowania plików asystenta: ' . $e->getMessage(), [
                'exception' => $e,
                'assistant_id' => $this->id,
                'openai_assistant_id' => $this->openai_assistant_id,
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Resetuje vector store asystenta
     * 
     * @return bool Czy operacja się powiodła
     */
    protected function resetVectorStore(): bool
    {
        try {
            $client = \OpenAI::client(config('openai.api_key'));
            
            // Jeśli mamy zapisany vector_store_id, usuwamy vector store
            if (!empty($this->vector_store_id)) {
                try {
                    $client->vectorStores()->delete($this->vector_store_id);
                    $this->vector_store_id = null;
                    $this->saveQuietly();
                    return true;
                } catch (\Exception $e) {
                    Log::warning("Nie udało się usunąć vector store {$this->vector_store_id}: " . $e->getMessage());
                }
            }
            
            // Jeśli nie mamy zapisanego vector_store_id, szukamy vector store dla tego asystenta
            try {
                $vectorStores = $client->vectorStores()->list();

                foreach ($vectorStores->data as $vectorStore) {
                    if (isset($vectorStore->metadata['assistant_id']) && $vectorStore->metadata['assistant_id'] === $this->openai_assistant_id) {
                        $client->vectorStores()->delete($vectorStore->id);
                        return true;
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Nie udało się znaleźć i usunąć vector store dla asystenta: " . $e->getMessage());
            }
            
            return false;
        } catch (\Exception $e) {
            Log::error('Błąd podczas resetowania vector store: ' . $e->getMessage(), [
                'exception' => $e,
                'assistant_id' => $this->id,
                'openai_assistant_id' => $this->openai_assistant_id,
            ]);
            
            return false;
        }
    }
    
    /**
     * Usuwa wszystkie pliki asystenta z OpenAI i z bazy danych
     * 
     * @return int Liczba usuniętych plików
     */
    protected function deleteAllFiles(): int
    {
        $deletedCount = 0;
        $client = \OpenAI::client(config('openai.api_key'));
        
        // Pobieramy wszystkie pliki asystenta z bazy danych
        $files = $this->files;

        // Usuwamy wszystkie pliki z bazy danych
        foreach ($files as $file) {
            try {
                // Próbujemy usunąć plik z OpenAI (może się nie udać, jeśli plik już nie istnieje)
                $client->files()->delete($file->openai_file_id);
                $deletedCount++;
            } catch (\Exception $e) {
                Log::warning("Nie udało się usunąć pliku {$file->openai_file_id} z OpenAI: " . $e->getMessage());
            }

            // Usuwamy plik z bazy danych
            $file->delete();
        }
        
        return $deletedCount;
    }

    /**
     * Aktualizuje wiedzę asystenta poprzez usunięcie wszystkich plików i dodanie nowych.
     * To efektywnie tworzy nowy vector store w OpenAI.
     *
     * @param array $paths Tablica ścieżek do nowych plików
     * @param int|null $threadId ID wątku (opcjonalne)
     * @return array Tablica z informacjami o rezultacie operacji
     */
    public function updateKnowledge(array $paths, ?int $threadId = null): array
    {
        try {
            // Sprawdzamy, czy openai_assistant_id istnieje
            if (empty($this->openai_assistant_id)) {
                Log::warning('Próba aktualizacji wiedzy asystenta bez openai_assistant_id');
                return [
                    'success' => false,
                    'message' => 'Asystent nie ma identyfikatora OpenAI.',
                    'error' => 'openai_assistant_id is null',
                ];
            }

            // Przesyłamy pliki do OpenAI
            $uploadResult = $this->uploadFiles($paths);
            
            if (empty($uploadResult['uploaded_files'])) {
                return [
                    'success' => false,
                    'message' => 'Nie udało się przesłać żadnego pliku.',
                    'errors' => $uploadResult['errors'],
                ];
            }

            // Tworzymy nowy vector store
            $vectorStoreResult = $this->createAndLinkVectorStore($uploadResult['uploaded_files'], $threadId);
            
            if (!$vectorStoreResult['success']) {
                // Usuwamy przesłane pliki, jeśli nie udało się utworzyć vector store
                $this->cleanupUploadedFiles($uploadResult['uploaded_files']);
                
                return [
                    'success' => false,
                    'message' => 'Wystąpił błąd podczas tworzenia vector store.',
                    'error' => $vectorStoreResult['error'] ?? 'Unknown error',
                ];
            }
            
            return [
                'success' => true,
                'message' => 'Wiedza asystenta została zaktualizowana.',
                'files_added' => count($vectorStoreResult['files']),
                'vector_store_id' => $vectorStoreResult['vector_store_id'],
                'errors' => $uploadResult['errors'],
            ];
        } catch (\Exception $e) {
            Log::error('Błąd podczas aktualizacji wiedzy asystenta: ' . $e->getMessage(), [
                'exception' => $e,
                'assistant_id' => $this->id,
                'openai_assistant_id' => $this->openai_assistant_id,
            ]);

            return [
                'success' => false,
                'message' => 'Wystąpił błąd podczas aktualizacji wiedzy asystenta.',
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Przesyła pliki do OpenAI
     * 
     * @param array $paths Tablica ścieżek do plików
     * @return array Tablica z przesłanymi plikami i błędami
     */
    protected function uploadFiles(array $paths): array
    {
        $client = \OpenAI::client(config('openai.api_key'));
        $uploadedFiles = [];
        $errors = [];
        
        foreach ($paths as $path) {
            try {
                if (is_file($path) === false) {
                    $errors[] = [
                        'path' => $path,
                        'message' => 'File not found'
                    ];
                    continue;
                }

                // Przesyłamy plik do OpenAI
                $response = $client->files()->upload([
                    'purpose' => 'assistants',
                    'file' => fopen($path, 'r'),
                ]);

                $uploadedFiles[] = $response->id;
            } catch (\Exception $e) {
                $errors[] = [
                    'path' => $path,
                    'message' => $e->getMessage()
                ];
                Log::error("Nie udało się przesłać pliku {$path}: " . $e->getMessage());
            }
        }
        
        return [
            'uploaded_files' => $uploadedFiles,
            'errors' => $errors,
        ];
    }
    
    /**
     * Tworzy i linkuje vector store z przesłanymi plikami
     * 
     * @param array $fileIds Tablica identyfikatorów plików w OpenAI
     * @param int|null $threadId ID wątku (opcjonalne)
     * @return array Rezultat operacji
     */
    protected function createAndLinkVectorStore(array $fileIds, ?int $threadId = null): array
    {
        try {
            $client = \OpenAI::client(config('openai.api_key'));
            
            // Najpierw resetujemy istniejący vector store
            $this->resetVectorStore();
            
            // Tworzymy nowy vector store
            $vectorStore = $client->vectorStores()->create([
                'name' => 'Vector store for assistant ' . $this->name,
                'metadata' => [
                    'assistant_id' => $this->openai_assistant_id
                ],
            ]);
            
            // Zapisujemy vector_store_id w bazie danych
            $this->vector_store_id = $vectorStore->id;
            $this->saveQuietly();
            
            // Dodajemy pliki do vector store
            foreach ($fileIds as $fileId) {
                $client->vectorStores()->files()->create($vectorStore->id, [
                    'file_id' => $fileId,
                ]);
            }
            
            // Aktualizujemy asystenta, aby używał nowego vector store
            $client->assistants()->modify($this->openai_assistant_id, [
                'tools' => [
                    [
                        'type' => 'file_search',
                        'retrieval_tool_config' => [
                            'vector_store_ids' => [$vectorStore->id]
                        ]
                    ],
                ],
                'file_ids' => $fileIds,
            ]);
            
            // Zapisujemy pliki w bazie danych
            $files = [];
            foreach ($fileIds as $fileId) {
                // Jeśli nie podano thread_id, znajdź lub utwórz systemowy wątek
                if (!$threadId) {
                    $threadId = $this->getOrCreateSystemThreadId();
                }
                
                // Zapisujemy plik w bazie danych
                $file = File::create([
                    'openai_file_id' => $fileId,
                    'assistant_id' => $this->id,
                    'thread_id' => $threadId,
                ]);
                
                $files[] = $file;
            }
            
            return [
                'success' => true,
                'vector_store_id' => $vectorStore->id,
                'files' => $files,
            ];
        } catch (\Exception $e) {
            Log::error('Błąd podczas tworzenia vector store: ' . $e->getMessage(), [
                'exception' => $e,
                'assistant_id' => $this->id,
                'openai_assistant_id' => $this->openai_assistant_id,
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Znajduje lub tworzy systemowy wątek dla asystenta
     * 
     * @return int ID wątku
     */
    protected function getOrCreateSystemThreadId(): int
    {
        // Sprawdzamy, czy istnieje jakiś wątek dla tego asystenta
        $thread = $this->threads()->first();
        
        if (!$thread) {
            // Jeśli nie ma żadnego wątku, tworzymy tymczasowy wątek systemowy
            $thread = $this->threads()->create([
                'uuid' => uniqid('system_'),
                'model_id' => 0,
                'model_type' => 'System',
            ]);
        }
        
        return $thread->id;
    }
    
    /**
     * Usuwa przesłane pliki w przypadku błędu
     * 
     * @param array $fileIds Tablica identyfikatorów plików w OpenAI
     * @return void
     */
    protected function cleanupUploadedFiles(array $fileIds): void
    {
        $client = \OpenAI::client(config('openai.api_key'));
        
        foreach ($fileIds as $fileId) {
            try {
                $client->files()->delete($fileId);
            } catch (\Exception $e) {
                Log::warning("Nie udało się usunąć pliku {$fileId}: " . $e->getMessage());
            }
        }
    }

    /**
     * Powiązuje vector store z asystentem.
     *
     * @param string $vectorStoreId ID vector store do powiązania
     * @return bool
     */
    public function linkVectorStore(string $vectorStoreId): bool
    {
        try {
            // Sprawdzamy, czy openai_assistant_id istnieje
            if (empty($this->openai_assistant_id)) {
                Log::warning('Próba powiązania vector store z asystentem bez openai_assistant_id');
                return false;
            }

            $client = \OpenAI::client(config('openai.api_key'));

            // Sprawdzamy, czy vector store istnieje
            try {
                $vectorStore = $client->vectorStores()->retrieve($vectorStoreId);
                ray('Vector store znaleziony:', [
                    'id' => $vectorStore->id,
                    'name' => $vectorStore->name,
                    'status' => $vectorStore->status
                ]);
            } catch (\Exception $e) {
                Log::error("Vector store o ID {$vectorStoreId} nie istnieje: " . $e->getMessage());
                return false;
            }

            // Pobieramy aktualną konfigurację asystenta
            $currentAssistant = $client->assistants()->retrieve($this->openai_assistant_id);
            $currentTools = $currentAssistant->tools ?? [];

            // Sprawdzamy, czy narzędzie file_search już istnieje
            $hasFileSearch = false;
            foreach ($currentTools as $key => $tool) {
                if ($tool->type === 'file_search') {
                    $hasFileSearch = true;
                    break;
                }
            }

            // Jeśli nie ma file_search, dodajemy je
            if (!$hasFileSearch) {
                $currentTools[] = ['type' => 'file_search'];
            }

            // Aktualizujemy asystenta
            $updateParams = [
                'tools' => $currentTools,
                'tool_resources' => [
                    'file_search' => [
                        'vector_store_ids' => [$vectorStoreId]
                    ]
                ],
                'metadata' => [
                    'vector_store_id' => $vectorStoreId
                ]
            ];
            
            ray('Parametry aktualizacji asystenta:', $updateParams);
            
            $updatedAssistant = $client->assistants()->modify($this->openai_assistant_id, $updateParams);
            
            ray('Asystent zaktualizowany:', [
                'id' => $updatedAssistant->id,
                'tools' => $updatedAssistant->tools,
                'tool_resources' => $updatedAssistant->tool_resources ?? null,
                'metadata' => $updatedAssistant->metadata ?? null
            ]);

            // Zapisujemy vector_store_id w bazie danych
            $this->vector_store_id = $vectorStoreId;
            $this->saveQuietly();

            return true;
        } catch (\Exception $e) {
            Log::error('Błąd podczas powiązania vector store z asystentem: ' . $e->getMessage(), [
                'exception' => $e,
                'assistant_id' => $this->id,
                'openai_assistant_id' => $this->openai_assistant_id,
                'vector_store_id' => $vectorStoreId,
            ]);
            ray('Błąd podczas powiązania vector store:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return false;
        }
    }

    /**
     * Powiązuje wiele vector stores z asystentem.
     *
     * @param array $vectorStoreIds Tablica ID vector stores do powiązania
     * @return bool
     */
    public function linkMultipleVectorStores(array $vectorStoreIds): bool
    {
        try {
            // Sprawdzamy, czy openai_assistant_id istnieje
            if (empty($this->openai_assistant_id)) {
                Log::warning('Próba powiązania vector stores z asystentem bez openai_assistant_id');
                return false;
            }

            if (empty($vectorStoreIds)) {
                Log::warning('Próba powiązania pustej tablicy vector stores z asystentem');
                return false;
            }

            $client = \OpenAI::client(config('openai.api_key'));

            // Sprawdzamy, czy wszystkie vector stores istnieją
            $validVectorStoreIds = $this->validateVectorStoreIds($vectorStoreIds);

            if (empty($validVectorStoreIds)) {
                Log::error("Żaden z podanych vector stores nie istnieje");
                return false;
            }

            // Aktualizujemy asystenta, aby używał podanych vector stores
            $client->assistants()->modify($this->openai_assistant_id, [
                'tools' => [
                    [
                        'type' => 'file_search',
                        'retrieval_tool_config' => [
                            'vector_store_ids' => $validVectorStoreIds
                        ]
                    ],
                ],
            ]);

            // Zapisujemy pierwszy vector_store_id w bazie danych (dla kompatybilności)
            $this->vector_store_id = $validVectorStoreIds[0];
            $this->saveQuietly();

            return true;
        } catch (\Exception $e) {
            Log::error('Błąd podczas powiązania vector stores z asystentem: ' . $e->getMessage(), [
                'exception' => $e,
                'assistant_id' => $this->id,
                'openai_assistant_id' => $this->openai_assistant_id,
                'vector_store_ids' => $vectorStoreIds,
            ]);
            
            return false;
        }
    }
    
    /**
     * Sprawdza, które vector store IDs są prawidłowe
     * 
     * @param array $vectorStoreIds Tablica ID vector stores do sprawdzenia
     * @return array Tablica prawidłowych ID vector stores
     */
    protected function validateVectorStoreIds(array $vectorStoreIds): array
    {
        $client = \OpenAI::client(config('openai.api_key'));
        $validVectorStoreIds = [];
        
        foreach ($vectorStoreIds as $vectorStoreId) {
            try {
                $client->vectorStores()->retrieve($vectorStoreId);
                $validVectorStoreIds[] = $vectorStoreId;
            } catch (\Exception $e) {
                Log::error("Vector store o ID {$vectorStoreId} nie istnieje: " . $e->getMessage());
            }
        }
        
        return $validVectorStoreIds;
    }

    /**
     * Przeszukuje vector store asystenta.
     *
     * @param string $query Zapytanie do przeszukania
     * @param int $limit Maksymalna liczba wyników
     * @param string|null $vectorStoreId ID vector store do przeszukania (opcjonalne)
     * @return array|null Wyniki przeszukiwania lub null w przypadku błędu
     */
    public function searchVectorStore(string $query, int $limit = 10, ?string $vectorStoreId = null): ?array
    {
        try {
            // Sprawdzamy, czy openai_assistant_id istnieje
            if (empty($this->openai_assistant_id)) {
                Log::warning('Próba przeszukania vector store asystenta bez openai_assistant_id');
                return null;
            }

            // Jeśli nie podano vector_store_id, używamy zapisanego w bazie danych lub szukamy powiązanego
            if (empty($vectorStoreId)) {
                $vectorStoreId = $this->getEffectiveVectorStoreId();
                
                if (empty($vectorStoreId)) {
                    Log::warning('Asystent nie ma powiązanego vector store');
                    return null;
                }
            }

            // Przeszukujemy vector store używając asystenta
            $searchResult = $this->searchVectorStoreWithAssistant($query, $limit);
            
            return [
                'query' => $query,
                'vector_store_id' => $vectorStoreId,
                'results' => $searchResult,
            ];
        } catch (\Exception $e) {
            Log::error('Błąd podczas przeszukiwania vector store: ' . $e->getMessage(), [
                'exception' => $e,
                'assistant_id' => $this->id,
                'openai_assistant_id' => $this->openai_assistant_id,
                'query' => $query,
            ]);
            
            return null;
        }
    }
    
    /**
     * Pobiera efektywny vector store ID (zapisany lub z powiązanych)
     * 
     * @return string|null ID vector store lub null, jeśli nie znaleziono
     */
    protected function getEffectiveVectorStoreId(): ?string
    {
        if (!empty($this->vector_store_id)) {
            return $this->vector_store_id;
        }
        
        // Próbujemy znaleźć vector store asystenta
        $status = $this->checkVectorStoreStatus();
        
        if (!empty($status['linked_vector_store_ids'])) {
            return $status['linked_vector_store_ids'][0];
        }
        
        return null;
    }
    
    /**
     * Przeszukuje vector store używając asystenta
     * 
     * @param string $query Zapytanie do przeszukania
     * @param int $limit Maksymalna liczba wyników
     * @return array Wyniki przeszukiwania
     */
    protected function searchVectorStoreWithAssistant(string $query, int $limit): array
    {
        $client = \OpenAI::client(config('openai.api_key'));
        
        // Tworzymy tymczasowy wątek
        $threadResponse = $client->threads()->create([]);
        
        // Dodajemy wiadomość do wątku
        $client->threads()->messages()->create($threadResponse->id, [
            'role' => 'user',
            'content' => $query,
        ]);
        
        // Uruchamiamy asystenta z instrukcją, aby przeszukał vector store
        $runResponse = $client->threads()->runs()->create($threadResponse->id, [
            'assistant_id' => $this->openai_assistant_id,
            'instructions' => 'Przeszukaj vector store i zwróć najlepsze dopasowania do zapytania użytkownika. Odpowiedz tylko faktami z vector store, nie dodawaj własnych informacji. Ogranicz wyniki do ' . $limit . ' najlepszych dopasowań.',
        ]);
        
        // Czekamy na zakończenie uruchomienia (maksymalnie 30 sekund)
        $maxAttempts = 15;
        $attempts = 0;
        $runId = $runResponse->id;
        $runStatus = $runResponse->status;
        
        while ($runStatus !== 'completed' && $runStatus !== 'failed' && $attempts < $maxAttempts) {
            sleep(2);
            $runResponse = $client->threads()->runs()->retrieve($threadResponse->id, $runId);
            $runStatus = $runResponse->status;
            $attempts++;
        }
        
        if ($runStatus !== 'completed') {
            Log::warning("Nie udało się zakończyć przeszukiwania vector store. Status: {$runStatus}");
            return [];
        }
        
        // Pobieramy wiadomości z wątku
        $messagesResponse = $client->threads()->messages()->list($threadResponse->id);
        
        // Konwertujemy odpowiedź na tablicę
        $messagesArray = json_decode(json_encode($messagesResponse), true);
        
        return $messagesArray['data'] ?? [];
    }

    /**
     * Pobiera listę plików asystenta z OpenAI.
     *
     * @return array
     */
    public function getOpenAIFiles(): array
    {
        try {
            $client = \OpenAI::client(config('openai.api_key'));

            // Sprawdzamy, czy openai_assistant_id istnieje
            if (empty($this->openai_assistant_id)) {
                Log::warning('Próba pobrania plików asystenta bez openai_assistant_id');
                return [];
            }

            // Pobieramy informacje o asystencie, który zawiera listę file_ids
            $assistantData = $client->assistants()->retrieve($this->openai_assistant_id);
            $assistantArray = json_decode(json_encode($assistantData), true);

            // Jeśli asystent nie ma plików, zwracamy pustą tablicę
            if (!isset($assistantArray['file_ids']) || empty($assistantArray['file_ids'])) {
                return [];
            }

            // Pobieramy szczegóły każdego pliku
            $files = [];
            foreach ($assistantArray['file_ids'] as $fileId) {
                try {
                    $fileData = $client->files()->retrieve($fileId);
                    $fileArray = json_decode(json_encode($fileData), true);

                    $files[] = [
                        'id' => $fileArray['id'],
                        'name' => $fileArray['filename'],
                        'purpose' => $fileArray['purpose'],
                        'created_at' => isset($fileArray['created_at']) && is_numeric($fileArray['created_at'])
                            ? date('Y-m-d H:i:s', $fileArray['created_at'])
                            : (isset($fileArray['created_at']) ? $fileArray['created_at'] : null),
                        'bytes' => $fileArray['bytes'],
                    ];
                } catch (\Exception $e) {
                    Log::warning("Nie udało się pobrać informacji o pliku {$fileId}: " . $e->getMessage());
                }
            }

            return $files;
        } catch (\Exception $e) {
            ray($e->getMessage());
            Log::error($e->getMessage());
            return [];
        }
    }

    /**
     * Usuwa pojedynczy plik asystenta.
     *
     * @param string $fileId ID pliku do usunięcia
     * @return bool
     */
    public function removeFile(string $fileId): bool
    {
        try {
            $client = \OpenAI::client(config('openai.api_key'));

            // Sprawdzamy, czy openai_assistant_id istnieje
            if (empty($this->openai_assistant_id)) {
                Log::warning('Próba usunięcia pliku asystenta bez openai_assistant_id');
                return false;
            }

            // Pobieramy informacje o asystencie, który zawiera listę file_ids
            $assistantData = $client->assistants()->retrieve($this->openai_assistant_id);
            $assistantArray = json_decode(json_encode($assistantData), true);

            // Jeśli asystent nie ma plików, zwracamy false
            if (!isset($assistantArray['file_ids']) || empty($assistantArray['file_ids'])) {
                return false;
            }

            // Filtrujemy listę plików, usuwając ten, który chcemy usunąć
            $fileIds = array_filter($assistantArray['file_ids'], function($id) use ($fileId) {
                return $id !== $fileId;
            });

            // Aktualizujemy asystenta z nową listą plików
            $client->assistants()->modify($this->openai_assistant_id, [
                'file_ids' => array_values($fileIds),
            ]);

            // Próbujemy usunąć plik z OpenAI
            try {
                $client->files()->delete($fileId);
            } catch (\Exception $e) {
                Log::warning("Nie udało się usunąć pliku {$fileId} z OpenAI: " . $e->getMessage());
                // Kontynuujemy, ponieważ plik mógł już zostać usunięty lub być niedostępny
            }

            // Usuwamy plik z bazy danych
            $this->files()->where('openai_file_id', $fileId)->delete();

            return true;
        } catch (\Exception $e) {
            ray($e->getMessage());
            Log::error($e->getMessage());
            return false;
        }
    }

    /**
     * Dodaje plik do asystenta.
     *
     * @param string $path Ścieżka do pliku
     * @param int|null $threadId ID wątku (opcjonalne)
     * @return File|null
     */
    public function attachFile(string $path, ?int $threadId = null): ?File
    {
        try {
            if (is_file($path) === false) {
                throw new \Exception('File not found');
            }

            // Sprawdzamy, czy openai_assistant_id istnieje
            if (empty($this->openai_assistant_id)) {
                Log::warning('Próba dodania pliku do asystenta bez openai_assistant_id');
                return null;
            }

            $client = \OpenAI::client(config('openai.api_key'));

            // Przesyłamy plik do OpenAI
            $response = $client->files()->upload([
                'purpose' => 'assistants',
                'file' => fopen($path, 'r'),
            ]);
            $fileId = $response->id;

            // Pobieramy informacje o asystencie, który zawiera listę file_ids
            $assistantData = $client->assistants()->retrieve($this->openai_assistant_id);
            $assistantArray = json_decode(json_encode($assistantData), true);

            // Przygotowujemy nową listę plików
            $fileIds = isset($assistantArray['file_ids']) ? $assistantArray['file_ids'] : [];
            $fileIds[] = $fileId;

            // Aktualizujemy asystenta z nową listą plików
            $client->assistants()->modify($this->openai_assistant_id, [
                'file_ids' => $fileIds,
            ]);

            // Jeśli nie podano thread_id, tworzymy tymczasowy wątek
            if (!$threadId) {
                // Sprawdzamy, czy istnieje jakiś wątek dla tego asystenta
                $thread = $this->threads()->first();

                if (!$thread) {
                    // Jeśli nie ma żadnego wątku, tworzymy tymczasowy wątek systemowy
                    $thread = $this->threads()->create([
                        'uuid' => uniqid('system_'),
                        'model_id' => 0,
                        'model_type' => 'System',
                    ]);
                }

                $threadId = $thread->id;
            }

            // Zapisujemy plik w bazie danych
            return File::create([
                'openai_file_id' => $fileId,
                'assistant_id' => $this->id,
                'thread_id' => $threadId,
            ]);
        } catch (\Exception $e) {
            ray($e->getMessage());
            Log::error($e->getMessage());
            return null;
        }
    }

    /**
     * Dodaje wiele plików do asystenta.
     *
     * @param array $paths Tablica ścieżek do plików
     * @param int|null $threadId ID wątku (opcjonalne)
     * @return array Tablica obiektów File i błędów
     */
    public function attachFiles(array $paths, ?int $threadId = null): array
    {
        $result = [
            'files' => [],
            'errors' => []
        ];

        foreach ($paths as $path) {
            try {
                $file = $this->attachFile($path, $threadId);
                if ($file) {
                    $result['files'][] = $file;
                }
            } catch (\Exception $e) {
                $result['errors'][] = [
                    'path' => $path,
                    'message' => $e->getMessage()
                ];
                Log::error("Nie udało się dołączyć pliku {$path}: " . $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Sprawdza status vector store asystenta.
     *
     * @return array Informacje o statusie vector store
     */
    public function checkVectorStoreStatus(): array
    {
        try {
            // Sprawdzamy, czy openai_assistant_id istnieje
            if (empty($this->openai_assistant_id)) {
                return [
                    'status' => 'error',
                    'message' => 'Asystent nie ma identyfikatora OpenAI.',
                    'has_vector_store' => false,
                    'is_linked' => false,
                ];
            }

            $client = \OpenAI::client(config('openai.api_key'));

            // Pobieramy informacje o asystencie
            $assistantData = $client->assistants()->retrieve($this->openai_assistant_id);
            $assistantArray = json_decode(json_encode($assistantData), true);

            // Sprawdzamy, czy asystent ma narzędzie retrieval
            $hasRetrievalTool = false;
            $linkedVectorStoreIds = [];

            if (isset($assistantArray['tools']) && is_array($assistantArray['tools'])) {
                foreach ($assistantArray['tools'] as $tool) {
                    if (isset($tool['type']) && $tool['type'] === 'file_search') {
                        $hasRetrievalTool = true;

                        // Sprawdzamy, czy narzędzie ma skonfigurowane vector_store_ids
                        if (isset($tool['retrieval_tool_config']['vector_store_ids']) &&
                            is_array($tool['retrieval_tool_config']['vector_store_ids'])) {
                            $linkedVectorStoreIds = $tool['retrieval_tool_config']['vector_store_ids'];
                        }

                        break;
                    }
                }
            }

            // Sprawdzamy, czy mamy zapisany vector_store_id w bazie danych
            $hasVectorStoreId = !empty($this->vector_store_id);

            // Sprawdzamy, czy zapisany vector_store_id jest powiązany z asystentem
            $isLinked = $hasVectorStoreId && in_array($this->vector_store_id, $linkedVectorStoreIds);

            // Pobieramy vector store, jeśli istnieje
            $vectorStore = null;
            if ($hasVectorStoreId) {
                try {
                    $vectorStoreData = $client->vectorStores()->retrieve($this->vector_store_id);
                    $vectorStore = [
                        'id' => $vectorStoreData->id,
                        'name' => $vectorStoreData->name,
                    ];
                } catch (\Exception $e) {
                    // Vector store nie istnieje
                }
            }

            return [
                'status' => 'success',
                'has_retrieval_tool' => $hasRetrievalTool,
                'has_vector_store_id' => $hasVectorStoreId,
                'is_linked' => $isLinked,
                'vector_store_id' => $this->vector_store_id,
                'linked_vector_store_ids' => $linkedVectorStoreIds,
                'vector_store' => $vectorStore,
            ];
        } catch (\Exception $e) {
            ray($e->getMessage());
            Log::error('Błąd podczas sprawdzania statusu vector store: ' . $e->getMessage());

            return [
                'status' => 'error',
                'message' => 'Wystąpił błąd podczas sprawdzania statusu vector store.',
                'error' => $e->getMessage(),
                'has_vector_store' => false,
                'is_linked' => false,
            ];
        }
    }
}
