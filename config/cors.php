<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth'],

    'allowed_methods' => ['*'],

    // Replace with your real Vercel URL(s). Keep localhost for when you test locally too.
    'allowed_origins' => [
    'http://localhost:3000',
    'https://ripple-chat-six.vercel.app',
],
    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];