<?php

return [
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'api_org' => env('OPENAI_ORGANIZATION'),
    ],
    'assistant' => [
        'engine' => env('OPENAI_ASSISTANT_ENGINE', 'gpt-3.5-turbo-0125'),
    ],
    'table' => [
        'assistants' => 'ai_assistants',
        'messages' => 'ai_messages',
    ]
];
