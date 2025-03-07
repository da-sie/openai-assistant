<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Ustawienia dla testów
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('OPENAI_API_KEY=test-key'); 