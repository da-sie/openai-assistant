<?php

namespace DaSie\Openaiassistant\Models;

use DaSie\Openaiassistant\Enums\CheckmarkStatus;
use DaSie\Openaiassistant\Events\AssistantUpdatedEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Thread extends Model
{
    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(config('openai-assistant.table.threads'));
    }

    public function threadable()
    {
        return $this->morphTo();
    }

    public function assistant(): BelongsTo
    {
        return $this->belongsTo(Assistant::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }

    /**
     * @throws \Exception
     */
    public function scope($content): File
    {
        $path = $this->saveContentToFile($content);
        return $this->attachFile($path);
    }

    protected static function booted()
    {
        static::updated(function ($thread) {

        });

        static::deleted(function ($thread) {

        });

        static::created(function ($thread) {
            try {
                $client = \OpenAI::client(config('openai.api_key'));
                $response = $client->threads()->create([
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => 'Odpowiadaj po polsku dodając AI.',
                        ]
                    ],
                ]);
                $thread->openai_thread_id = $response->id;
                $thread->status = 'created';
                $thread->saveQuietly();
            } catch (\Exception $e) {
                ray($e->getMessage());
                event(new AssistantUpdatedEvent($this->assistant->uuid, ['steps' => ['initialized_ai' => CheckmarkStatus::failed]]));
            }
        });

    }

    public function delete()
    {
        $api = \OpenAI::client(config('openai.api_key'));

        if ($this->assistant_id) {
            try {
                $response = $api->assistants()->files()->list($this->assistant_id);
                foreach ($response->data as $result) {
                    $api->assistants()->files()->delete(
                        assistantId: $this->assistant_id,
                        fileId: $result->id
                    );
                }
                $api->assistants()->delete($this->assistant_id);
            } catch (\Exception $e) {
                \Log::error($e->getMessage());
            }
        }

        $this->messages()->delete();

        return parent::delete();
    }

    /**
     * Attach file to assistant, return file id
     * @param string $path
     * @return string
     */
    public function attachFile($path): File
    {
        if (is_file($path) === false) {
            throw new \Exception('File not found');
        }
        $client = \OpenAI::client(config('openai.api_key'));
        $response = $client->files()->upload([
            'purpose' => 'assistants',
            'file' => fopen($path, 'r'),
        ]);
        $fileId = $response->id;

        $response = $client
            ->assistants()
            ->files()
            ->create($this->assistant->openai_assistant_id, [
                'file_id' => $fileId,
            ]);
        return File::create([
            'openai_file_id' => $response->id,
            'assistant_id' => $this->assistant->id,
            'thread_id' => $this->id,
        ]);
    }

    /**
     * Save content to file and return file path
     * @param array|string|object $content
     * @return string
     */
    public function saveContentToFile(array|string|object $content): string
    {
        try {
            if (!is_string($content)) {
                $content = json_encode($content);
                $ext = 'json';
            } else {
                $ext = 'txt';
            }

            $filePath = storage_path('app/public/' . $this->uuid . '_' . time() . rand(1, 9999) . '.' . $ext);
            file_put_contents($filePath, $content);
            return $filePath;
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return '';
        }
    }
}
