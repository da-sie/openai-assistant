<?php

namespace DaSie\Openaiassistant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class File extends Model
{
    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(config('openai-assistant.table.files'));
    }

    protected function assistant(): BelongsTo
    {
        return $this->belongsTo(Assistant::class);
    }

    protected function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

}
