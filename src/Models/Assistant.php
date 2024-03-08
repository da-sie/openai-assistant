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
                $response = $client
                    ->assistants()
                    ->files()
                    ->list($assistant->openai_assistant_id);
                foreach ($response->data as $result) {
                    $client
                        ->assistants()
                        ->files()
                        ->delete(assistantId: $assistant->openai_assistant_id, fileId: $result->id);
                }
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

}
