<?php

namespace DaSie\Openaiassistant\Models;

use DaSie\Openaiassistant\Enums\CheckmarkStatus;
use DaSie\Openaiassistant\Enums\RequestMode;
use DaSie\Openaiassistant\Events\AssistantUpdatedEvent;
use DaSie\Openaiassistant\Events\OpenAiRequestEvent;
use DaSie\Openaiassistant\Helpers\OpenAiHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Assistant extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $guarded = [];

    protected OpenAiHelper $openAiHelper;

    protected $casts = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(config('openai-assistant.table.assistants'));
    }

    public function assistable()
    {
        return $this->morphTo();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('upload');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this
            ->addMediaConversion('preview')
            ->fit(Fit::Contain, 600, 600)
            ->nonQueued();
    }

    public function initialize($prePrompt = null): Assistant
    {
        $this->openAiHelper = new OpenAiHelper();
        $this->openAiHelper->assistant = $this;
        $this->openAiHelper->initialize($prePrompt ?? RequestMode::from($this->request_mode)->prePrompt());
        $this->save();

        return $this;
    }

    public function sendMessage($message, $responseType = 'text'): void
    {

        if (! $this->assistant_id || ! $this->thread_id) {
            throw new \Exception('Assistant not initialized');
        }

        if ($this->openAiHelper->assistantId == null) {
            $this->openAiHelper = new OpenAiHelper();
            $this->openAiHelper->assistant = $this;
        }

        event(new AssistantUpdatedEvent($this->uuid, ['steps' => ['processed_ai' => CheckmarkStatus::processing]]));

        $this->openAiHelper->sendMessage($message);

        $message = $this->messages()->create([
            'prompt' => $message,
            'message_id' => $this->openAiHelper->currentMessageId,
            'run_id' => $this->openAiHelper->runId,
            'run_status' => 'pending',
            'response_type' => $responseType,
        ]);

        event(new OpenAiRequestEvent($message->id));
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
}
