<?php

namespace DaSie\Openaiassistant\Jobs;

use App\Models\Upload;
use DaSie\Openaiassistant\Models\Assistant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ClearEmptyAssistantsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
    }

    public function handle(): void
    {
        Assistant::where('thread_id', '=', null)->delete();
    }
}
