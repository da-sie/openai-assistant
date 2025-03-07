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
                $client->assistants()->modify($assistant->openai_assistant_id, [
                    'name' => $assistant->name,
                    'instructions' => $assistant->instructions,
                    'model' => $assistant->engine,
                ]);
                // event(new AssistantUpdatedEvent($assistant->id, ['steps' => ['initialized_ai' => CheckmarkStatus::success]]));
            } catch (\Exception $e) {
                ray($e->getMessage());
                Log::error($e->getMessage());
            }
        });

        static::deleted(function ($assistant) {
            try {
                $client = \OpenAI::client(config('openai.api_key'));
                $client->assistants()->delete($assistant->openai_assistant_id);
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
                            'type' => 'retrieval',
                        ],
                    ],
                    'model' => $assistant->engine,
                ]);

                $assistant->openai_assistant_id = $assistantModel->id;
                $assistant->saveQuietly();
                // event(new AssistantUpdatedEvent($assistant->id, ['steps' => ['initialized_ai' => CheckmarkStatus::success]]));
            } catch (\Exception $e) {
                $assistant->delete();
                Log::error($e->getMessage());
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
            
            // Modyfikujemy asystenta, ustawiając pustą tablicę file_ids
            $client->assistants()->modify($this->openai_assistant_id, [
                'file_ids' => [],
            ]);
            
            // Pobieramy wszystkie pliki asystenta z bazy danych
            $files = $this->files;
            
            // Usuwamy wszystkie pliki z bazy danych
            foreach ($files as $file) {
                try {
                    // Próbujemy usunąć plik z OpenAI (może się nie udać, jeśli plik już nie istnieje)
                    $client->files()->delete($file->openai_file_id);
                } catch (\Exception $e) {
                    Log::warning("Nie udało się usunąć pliku {$file->openai_file_id} z OpenAI: " . $e->getMessage());
                }
                
                // Usuwamy plik z bazy danych
                $file->delete();
            }
            
            return $this;
        } catch (\Exception $e) {
            ray($e->getMessage());
            Log::error($e->getMessage());
            throw $e;
        }
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
                        'created_at' => date('Y-m-d H:i:s', $fileArray['created_at']),
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
            // Resetujemy pliki asystenta (usuwa wszystkie pliki i vector store)
            $this->resetFiles();
            
            // Dodajemy nowe pliki (tworzy nowy vector store)
            $result = $this->attachFiles($paths, $threadId);
            
            return [
                'success' => true,
                'message' => 'Wiedza asystenta została zaktualizowana.',
                'files_added' => count($result['files']),
                'errors' => $result['errors'],
            ];
        } catch (\Exception $e) {
            ray($e->getMessage());
            Log::error('Błąd podczas aktualizacji wiedzy asystenta: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Wystąpił błąd podczas aktualizacji wiedzy asystenta.',
                'error' => $e->getMessage(),
            ];
        }
    }
}
