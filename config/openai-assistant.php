<?php

return [
    'openai' => [
        'api_key' => env('OPENAI_API_KEY', 'test-key'),
        'api_org' => env('OPENAI_ORGANIZATION', null),
    ],
    'assistant' => [
        'engine' => env('OPENAI_ASSISTANT_ENGINE', 'gpt-3.5-turbo-0125'),
        'initial_message' => env('OPENAI_ASSISTANT_INITIAL_MESSAGE', null),
    ],
    'table' => [
        'assistants' => 'ai_assistants',
        'files' => 'ai_files',
        'threads' => 'ai_threads',
        'messages' => 'ai_messages',
    ],
    'queue' => env('OPENAI_ASSISTANT_QUEUE', 'default'),
];
