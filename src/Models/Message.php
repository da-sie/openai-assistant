<?php

namespace DaSie\Openaiassistant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(config('openai-assistant.table.messages'));
    }
    protected function assistant(): BelongsTo
    {
        return $this->belongsTo(Assistant::class);
    }
}
