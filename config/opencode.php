<?php

return [

    // Local OpenCode headless server. Leave as-is for a server on the same host.
    'base_url' => env('OPENCODE_BASE_URL', 'http://127.0.0.1:4096'),

    // Full request timeout (seconds).
    'timeout' => (int) env('OPENCODE_TIMEOUT', 5),

    // TCP connect timeout (seconds).
    'connect_timeout' => (int) env('OPENCODE_CONNECT_TIMEOUT', 3),

    // Optional Basic Auth; only sent when a password is configured (R-6).
    'basic_auth_user' => env('OPENCODE_SERVER_USER', 'opencode'),
    'basic_auth_password' => env('OPENCODE_SERVER_PASSWORD', ''),

    // Defensive caps so a loud status response never saturates the model context.
    'max_sessions' => (int) env('OPENCODE_MAX_SESSIONS', 20),
    'session_summary_char_cap' => (int) env('OPENCODE_SESSION_SUMMARY_CHAR_CAP', 500),

];
