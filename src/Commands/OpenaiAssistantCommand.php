<?php

namespace DaSie\OpenaiAssistant\Commands;

use Illuminate\Console\Command;

class OpenaiAssistantCommand extends Command
{
    public $signature = 'openai-assistant';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
