<?php

return [
    'mock_path' => env('JAMF_MOCK_PATH', base_path('../files/api-mock-response.json')),
    'role' => env('APP_ROLE', 'inventory'),
    'provider_mode' => env('PROVIDER_MODE', 'remote'),
    'provider_url' => env('JAMF_PROVIDER_URL', 'http://provider:8000/api/provider'),
];
