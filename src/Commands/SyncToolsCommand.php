<?php

namespace DaSie\Openaiassistant\Commands;

use DaSie\Openaiassistant\Models\Assistant;
use Illuminate\Console\Command;

class SyncToolsCommand extends Command
{
    public $signature = 'openai-assistant:sync-tools {--assistant= : Specific assistant name to sync}';

    public $description = 'Synchronize tool definitions with OpenAI assistants';

    public function handle(): int
    {
        $assistantName = $this->option('assistant');

        $query = Assistant::query();

        if ($assistantName) {
            $query->where('name', $assistantName);
        }

        $assistants = $query->get();

        if ($assistants->isEmpty()) {
            $this->error('No assistants found.');
            return self::FAILURE;
        }

        $this->info("Syncing tools for {$assistants->count()} assistant(s)...\n");

        $tools = config('openai-assistant.tools', []);
        $this->info('Configured tools:');
        $this->line('  - file_search (built-in)');
        foreach ($tools as $tool) {
            if ($tool['type'] === 'function') {
                $this->line("  - {$tool['function']['name']}");
            } else {
                $this->line("  - {$tool['type']}");
            }
        }
        $this->newLine();

        $success = 0;
        $failed = 0;

        foreach ($assistants as $assistant) {
            $this->info("Syncing: {$assistant->name} ({$assistant->openai_assistant_id})");

            if ($assistant->syncTools()) {
                $this->info("  [OK] Tools synchronized successfully");
                $success++;
            } else {
                $this->error("  [FAILED] Could not sync tools");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Done! Success: {$success}, Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
