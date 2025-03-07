<?php

namespace DaSie\Openaiassistant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class File extends Model
{
    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(config('openai-assistant.table.files'));
    }

    /**
     * Relacja do asystenta
     */
    public function assistant(): BelongsTo
    {
        return $this->belongsTo(Assistant::class);
    }

    /**
     * Relacja do wątku
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }
    
    /**
     * Pobiera szczegóły pliku z OpenAI
     * 
     * @return array|null Szczegóły pliku lub null w przypadku błędu
     */
    public function getDetails(): ?array
    {
        try {
            $client = \OpenAI::client(config('openai.api_key'));
            
            $fileData = $client->files()->retrieve($this->openai_file_id);
            $fileArray = json_decode(json_encode($fileData), true);
            
            return [
                'id' => $fileArray['id'],
                'name' => $fileArray['filename'] ?? null,
                'purpose' => $fileArray['purpose'] ?? null,
                'created_at' => isset($fileArray['created_at']) && is_numeric($fileArray['created_at'])
                    ? date('Y-m-d H:i:s', $fileArray['created_at'])
                    : (isset($fileArray['created_at']) ? $fileArray['created_at'] : null),
                'bytes' => $fileArray['bytes'] ?? null,
                'status' => $fileArray['status'] ?? null,
            ];
        } catch (\Exception $e) {
            Log::error('Błąd podczas pobierania szczegółów pliku: ' . $e->getMessage(), [
                'file_id' => $this->id,
                'openai_file_id' => $this->openai_file_id,
                'exception' => $e,
            ]);
            
            return null;
        }
    }
    
    /**
     * Usuwa plik z OpenAI
     * 
     * @return bool Czy operacja się powiodła
     */
    public function deleteFromOpenAI(): bool
    {
        try {
            $client = \OpenAI::client(config('openai.api_key'));
            
            $client->files()->delete($this->openai_file_id);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Błąd podczas usuwania pliku z OpenAI: ' . $e->getMessage(), [
                'file_id' => $this->id,
                'openai_file_id' => $this->openai_file_id,
                'exception' => $e,
            ]);
            
            return false;
        }
    }
    
    /**
     * Usuwa plik z OpenAI i z bazy danych
     * 
     * @return bool Czy operacja się powiodła
     */
    public function deleteWithOpenAI(): bool
    {
        $success = $this->deleteFromOpenAI();
        
        if ($success || !$this->exists) {
            $this->delete();
            return true;
        }
        
        return false;
    }
}
