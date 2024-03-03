<?php

namespace DaSie\Openaiassistant\Jobs;

use DaSie\Openaiassistant\Models\Assistant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CleanupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
    }

    public function handle(): void
    {
        dispatch(new ClearEmptyAssistantsJob());

        Assistant::where('created_at', '<', now()->subHours(6))->each(function ($upload) {
            if ($upload->assistant_id) {
                dispatch(new AssistantDeleteJob($upload->assistant_id));
                $upload->delete();
            }
        });
    }
}
