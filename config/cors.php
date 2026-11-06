<?php

return ['paths' => ['api/*'], 'allowed_methods' => ['*'], 'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:5173')], 'allowed_origins_patterns' => [], 'allowed_headers' => ['Content-Type', 'Accept', 'X-CSRF-TOKEN', 'X-Requested-With'], 'exposed_headers' => [], 'max_age' => 600, 'supports_credentials' => true];
