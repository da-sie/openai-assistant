<?php

declare(strict_types=1);

namespace DaSie\Openaiassistant\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ToolCallHandler
{
    /**
     * Handle tool calls from OpenAI Assistant
     *
     * @param array $toolCalls Array of tool call objects from OpenAI
     * @return array Array of tool outputs ready for submitToolOutputs
     */
    public function handle(array $toolCalls): array
    {
        $toolOutputs = [];

        foreach ($toolCalls as $toolCall) {
            $functionName = $toolCall->function->name ?? null;
            $arguments = json_decode($toolCall->function->arguments ?? '{}', true);

            Log::info('Processing tool call', [
                'tool_call_id' => $toolCall->id,
                'function_name' => $functionName,
                'arguments' => $arguments,
            ]);

            $result = $this->executeFunction($functionName, $arguments);

            $toolOutputs[] = [
                'tool_call_id' => $toolCall->id,
                'output' => json_encode($result, JSON_UNESCAPED_UNICODE),
            ];
        }

        return $toolOutputs;
    }

    /**
     * Execute a specific function based on name
     */
    protected function executeFunction(?string $functionName, array $arguments): array
    {
        $handlers = config('openai-assistant.tool_handlers', []);

        if (isset($handlers[$functionName])) {
            return $this->executeHandler($handlers[$functionName], $arguments);
        }

        return ['error' => "Unknown function: {$functionName}. Configure it in openai-assistant.tool_handlers."];
    }

    /**
     * Execute a configured handler
     */
    protected function executeHandler(array $handler, array $arguments): array
    {
        $type = $handler['type'] ?? 'http';

        return match ($type) {
            'http' => $this->executeHttpHandler($handler, $arguments),
            'class' => $this->executeClassHandler($handler, $arguments),
            default => ['error' => "Unknown handler type: {$type}"],
        };
    }

    /**
     * Execute HTTP handler (call internal or external API)
     */
    protected function executeHttpHandler(array $handler, array $arguments): array
    {
        try {
            $method = strtoupper($handler['method'] ?? 'GET');
            $url = $handler['url'];

            // Replace placeholders in URL
            foreach ($arguments as $key => $value) {
                if (is_scalar($value)) {
                    $url = str_replace("{{$key}}", (string) $value, $url);
                }
            }

            // If URL is relative, make it absolute using app URL
            if (! str_starts_with($url, 'http')) {
                $url = rtrim(config('app.url'), '/').'/'.ltrim($url, '/');
            }

            Log::info('Executing HTTP tool call', [
                'method' => $method,
                'url' => $url,
                'arguments' => $arguments,
            ]);

            $response = match ($method) {
                'GET' => Http::get($url, $arguments),
                'POST' => Http::post($url, $arguments),
                'PUT' => Http::put($url, $arguments),
                'DELETE' => Http::delete($url, $arguments),
                default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
            };

            if ($response->successful()) {
                return $response->json() ?? ['success' => true];
            }

            return [
                'error' => 'HTTP request failed',
                'status' => $response->status(),
                'message' => $response->body(),
            ];
        } catch (\Exception $e) {
            Log::error('Tool call HTTP handler error', [
                'handler' => $handler,
                'arguments' => $arguments,
                'error' => $e->getMessage(),
            ]);

            return [
                'error' => 'Failed to execute tool',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Execute class handler
     */
    protected function executeClassHandler(array $handler, array $arguments): array
    {
        try {
            $class = $handler['class'];
            $method = $handler['method'] ?? 'handle';

            if (! class_exists($class)) {
                return ['error' => "Handler class not found: {$class}"];
            }

            $instance = app($class);

            if (! method_exists($instance, $method)) {
                return ['error' => "Handler method not found: {$class}::{$method}"];
            }

            $result = $instance->$method($arguments);

            return is_array($result) ? $result : ['result' => $result];
        } catch (\Exception $e) {
            Log::error('Tool call class handler error', [
                'handler' => $handler,
                'arguments' => $arguments,
                'error' => $e->getMessage(),
            ]);

            return [
                'error' => 'Failed to execute tool',
                'message' => $e->getMessage(),
            ];
        }
    }
}
