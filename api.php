<?php
// api.php - Backend untuk Ulems (MySQL)
// Pastikan PHP tidak menampilkan error sebagai HTML
ini_set('display_errors', 0);
error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, x-access-key');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ================== DATABASE SETUP ==================
$db_host = '127.0.0.1';
$db_user = 'root';
$db_pass = '';
$db_name = 'hardi_ulems';

try {
    $temp = new PDO("mysql:host=$db_host", $db_user, $db_pass);
    $temp->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $temp->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $temp = null;

    $db = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => ['Database connection failed: ' . $e->getMessage()]]);
    exit;
}

$db->exec("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255),
    password VARCHAR(255),
    name VARCHAR(255),
    tz VARCHAR(100) DEFAULT 'Asia/Jakarta',
    is_filter TINYINT(1) DEFAULT 0,
    is_confetti_animation TINYINT(1) DEFAULT 1,
    can_reply TINYINT(1) DEFAULT 1,
    can_edit TINYINT(1) DEFAULT 1,
    can_delete TINYINT(1) DEFAULT 1,
    tenor_key VARCHAR(255) DEFAULT NULL,
    access_key VARCHAR(255),
    token VARCHAR(255)
)");

$db->exec("CREATE TABLE IF NOT EXISTS comments (
    id VARCHAR(100) PRIMARY KEY,
    own VARCHAR(100),
    name VARCHAR(255),
    presence TINYINT(1) DEFAULT 0,
    comment TEXT,
    gif_url VARCHAR(500) DEFAULT NULL,
    is_admin TINYINT(1) DEFAULT 0,
    is_reply TINYINT(1) DEFAULT 0,
    parent_id VARCHAR(100) DEFAULT NULL,
    created_at VARCHAR(100),
    likes INT DEFAULT 0,
    ip VARCHAR(100) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL
)");

// Seed admin user
$stmt = $db->query("SELECT COUNT(*) FROM users");
if ($stmt->fetchColumn() == 0) {
    $access_key = 'd9faced3377732b0edf19e90d1bde0cd5de04801c75eb41743';
    // JWT-like token (3 parts separated by dots) so isAdmin() returns true
    $payload = base64_encode(json_encode(['exp' => time() + 86400 * 365 * 10, 'email' => 'admin@admin.com']));
    $token = 'local.' . $payload . '.sig';
    $db->prepare("INSERT INTO users (email, password, name, access_key, token) VALUES (?, ?, ?, ?, ?)")
       ->execute(['admin@admin.com', 'password', 'Hardi & Febri', $access_key, $token]);
}

// ================== ROUTER ==================
$method = $_SERVER['REQUEST_METHOD'];
$route_full = $_GET['route'] ?? '/';
$route_parts = explode('?', $route_full, 2);
$route = $route_parts[0];
if (isset($route_parts[1])) {
    parse_str($route_parts[1], $query_params);
    $_GET = array_merge($_GET, $query_params);
}
$body = json_decode(file_get_contents('php://input'), true) ?: [];

function getHeader($name) {
    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        foreach ($headers as $k => $v) {
            if (strtolower($k) === strtolower($name)) return $v;
        }
    }
    $normalized = 'HTTP_' . str_replace('-', '_', strtoupper($name));
    return $_SERVER[$normalized] ?? '';
}

function respond($code, $data = null, $error = null) {
    http_response_code($code);
    $res = [];
    if ($error) {
        $res['error'] = [$error];
        $res['id'] = time();
    } else {
        $res['code'] = $code;
        $res['data'] = $data;
    }
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

function formatComment($row) {
    return [
        'uuid'       => $row['id'],
        'own'        => $row['own'] ?? $row['id'],
        'name'       => $row['name'],
        'presence'   => (bool)$row['presence'],
        'comment'    => $row['comment'],
        'gif_url'    => $row['gif_url'] ?? null,
        'created_at' => $row['created_at'],
        'is_admin'   => (bool)$row['is_admin'],
        'is_parent'  => (int)$row['is_reply'] === 0,
        'ip'         => $row['ip'] ?? null,
        'user_agent' => $row['user_agent'] ?? null,
        'like_count' => (int)$row['likes'],
        'comments'   => [],
    ];
}

// ================== LOGIN ==================
if ($route === '/api/session' && $method === 'POST') {
    $email = $body['email'] ?? '';
    $password = $body['password'] ?? '';
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND password = ?");
    $stmt->execute([$email, $password]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        // Regenerate token on each login
        $payload = base64_encode(json_encode(['exp' => time() + 86400 * 365, 'email' => $user['email']]));
        $token = 'local.' . $payload . '.sig';
        $db->prepare("UPDATE users SET token = ? WHERE id = ?")->execute([$token, $user['id']]);
        // Return 200 because session.js checks for HTTP_STATUS_OK (200)
        respond(200, ['token' => $token]);
    }
    respond(400, null, 'Email atau password salah');
}

// ================== CONFIG (Guest page) ==================
if ($route === '/api/v2/config' && $method === 'GET') {
    $stmt = $db->query("SELECT * FROM users LIMIT 1");
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        respond(200, []);
    }
    respond(200, [
        'is_confetti_animation' => (bool)$user['is_confetti_animation'],
        'can_reply' => (bool)$user['can_reply'],
        'can_edit' => (bool)$user['can_edit'],
        'can_delete' => (bool)$user['can_delete'],
        'tz' => $user['tz'],
        'is_filter' => (bool)$user['is_filter']
    ]);
}

// ================== USER (Admin Dashboard) ==================
if ($route === '/api/user') {
    $token = str_replace('Bearer ', '', getHeader('Authorization'));
    $stmt = $db->prepare("SELECT * FROM users WHERE token = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) respond(401, null, 'Sesi habis, silakan login lagi.');

    if ($method === 'GET') {
        respond(200, $user);
    }

    if ($method === 'PATCH') {
        $updates = [];
        $params = [];
        foreach (['name', 'tz', 'is_filter', 'is_confetti_animation', 'can_reply', 'can_edit', 'can_delete', 'tenor_key'] as $key) {
            if (array_key_exists($key, $body)) {
                $updates[] = "`$key` = ?";
                $params[] = $body[$key];
            }
        }
        if (isset($body['new_password']) && isset($body['old_password'])) {
            if ($user['password'] === $body['old_password']) {
                $updates[] = "password = ?";
                $params[] = $body['new_password'];
            } else {
                respond(400, null, 'Password lama salah.');
            }
        }
        if (count($updates) > 0) {
            $params[] = $user['id'];
            $db->prepare("UPDATE users SET " . implode(", ", $updates) . " WHERE id = ?")->execute($params);
        }
        respond(200, ['status' => true]);
    }
}

// ================== STATS ==================
if ($route === '/api/stats' && $method === 'GET') {
    $comments = $db->query("SELECT COUNT(*) FROM comments WHERE is_reply = 0")->fetchColumn();
    $present = $db->query("SELECT COUNT(*) FROM comments WHERE presence = 1")->fetchColumn();
    $absent = $db->query("SELECT COUNT(*) FROM comments WHERE presence = 0 AND is_reply = 0")->fetchColumn();
    $likes = $db->query("SELECT COALESCE(SUM(likes), 0) FROM comments")->fetchColumn();
    respond(200, [
        'comments' => (int)$comments,
        'likes' => (int)$likes,
        'present' => (int)$present,
        'absent' => (int)$absent
    ]);
}

// ================== KEY ==================
if ($route === '/api/key' && $method === 'PUT') {
    $token = str_replace('Bearer ', '', getHeader('Authorization'));
    $stmt = $db->prepare("SELECT id FROM users WHERE token = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) respond(401, null, 'Unauthorized');
    
    $new_key = bin2hex(random_bytes(24));
    $db->prepare("UPDATE users SET access_key = ? WHERE id = ?")->execute([$new_key, $user['id']]);
    respond(200, ['access_key' => $new_key]);
}

// ================== GET COMMENTS ==================
if ($route === '/api/v2/comment' && $method === 'GET') {
    $per = (int)($_GET['per'] ?? 10);
    $next = (int)($_GET['next'] ?? 0);

    $stmt = $db->prepare("SELECT * FROM comments WHERE is_reply = 0 ORDER BY created_at DESC LIMIT :lim OFFSET :off");
    $stmt->bindValue(':lim', $per, PDO::PARAM_INT);
    $stmt->bindValue(':off', $next, PDO::PARAM_INT);
    $stmt->execute();
    $parents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($parents as $p) {
        $item = formatComment($p);
        $st = $db->prepare("SELECT * FROM comments WHERE parent_id = ? ORDER BY created_at ASC");
        $st->execute([$p['id']]);
        $children = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($children as $c) {
            $item['comments'][] = formatComment($c);
        }
        $result[] = $item;
    }

    $total = (int)$db->query("SELECT COUNT(*) FROM comments WHERE is_reply = 0")->fetchColumn();

    respond(200, [
        'count' => $total,
        'lists' => $result
    ]);
}

// ================== POST COMMENT ==================
if ($route === '/api/comment' && $method === 'POST') {
    $id = uniqid('c', true);
    $own = uniqid('o', true);
    $name = $body['name'] ?? '';
    $presence = !empty($body['presence']) ? 1 : 0;
    $comment = $body['comment'] ?? '';
    $gif_url = $body['gif_url'] ?? null;
    $auth_header = getHeader('Authorization');
    $is_admin = strlen($auth_header) > 10 ? 1 : 0;
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    $is_reply = 0;
    $parent_id = null;
    if (!empty($body['id'])) {
        $is_reply = 1;
        $parent_id = $body['id'];
    }

    $created_at = gmdate("Y-m-d\TH:i:s.000\Z");

    $stmt = $db->prepare("INSERT INTO comments (id, own, name, presence, comment, gif_url, is_admin, is_reply, parent_id, created_at, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$id, $own, $name, $presence, $comment, $gif_url, $is_admin, $is_reply, $parent_id, $created_at, $ip, $user_agent]);

    respond(201, [
        'uuid'       => $id,
        'own'        => $own,
        'name'       => $name,
        'presence'   => (bool)$presence,
        'comment'    => $comment,
        'gif_url'    => $gif_url,
        'created_at' => $created_at,
        'is_admin'   => (bool)$is_admin,
        'is_parent'  => $is_reply === 0,
        'ip'         => $ip,
        'user_agent' => $user_agent,
        'like_count' => 0,
        'comments'   => [],
    ]);
}

// ================== COMMENT ACTIONS (edit/delete/like/unlike) ==================
if (preg_match('#^/api/comment/([^/]+)$#', $route, $matches)) {
    $id = $matches[1];

    if ($method === 'PUT') {
        $comment = $body['comment'] ?? '';
        $presence = !empty($body['presence']) ? 1 : 0;
        $db->prepare("UPDATE comments SET comment = ?, presence = ? WHERE id = ? OR own = ?")->execute([$comment, $presence, $id, $id]);
        respond(200, ['status' => true]);
    }

    if ($method === 'DELETE') {
        // Delete by id or own
        $db->prepare("DELETE FROM comments WHERE id = ? OR own = ? OR parent_id = ? OR parent_id = (SELECT sub.id FROM (SELECT id FROM comments WHERE own = ?) AS sub)")->execute([$id, $id, $id, $id]);
        respond(200, ['status' => true]);
    }

    if ($method === 'POST') { // LIKE
        $db->prepare("UPDATE comments SET likes = likes + 1 WHERE id = ?")->execute([$id]);
        $like_own = uniqid('like_');
        respond(201, ['uuid' => $like_own]);
    }

    if ($method === 'PATCH') { // UNLIKE
        $db->prepare("UPDATE comments SET likes = GREATEST(0, likes - 1) WHERE id = ?")->execute([$id]);
        respond(200, ['status' => true]);
    }
}

// ================== DOWNLOAD CSV ==================
if ($route === '/api/download' && $method === 'GET') {
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"guest_list.csv\"");
    echo "\xEF\xBB\xBF"; // BOM for Excel
    echo "Nama,Kehadiran,Komentar,Tanggal\n";
    $stmt = $db->query("SELECT name, presence, comment, created_at FROM comments WHERE is_reply = 0 ORDER BY created_at DESC");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $kehadiran = $row['presence'] == 1 ? 'Hadir' : 'Tidak Hadir';
        $name = str_replace('"', '""', $row['name']);
        $comment = str_replace('"', '""', $row['comment']);
        echo "\"$name\",\"$kehadiran\",\"$comment\",\"{$row['created_at']}\"\n";
    }
    exit;
}

respond(404, null, 'Route tidak ditemukan: ' . $route);
