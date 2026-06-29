<?php
// migrate.php - Satu kali jalankan untuk update database
$db = new PDO('mysql:host=127.0.0.1;dbname=hardi_ulems;charset=utf8mb4', 'root', '');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Add missing columns
$columns = [
    "ALTER TABLE comments ADD COLUMN own VARCHAR(100) AFTER id",
    "ALTER TABLE comments ADD COLUMN gif_url VARCHAR(500) DEFAULT NULL AFTER comment",
    "ALTER TABLE comments ADD COLUMN ip VARCHAR(100) DEFAULT NULL AFTER likes",
    "ALTER TABLE comments ADD COLUMN user_agent TEXT DEFAULT NULL AFTER ip",
];

foreach ($columns as $sql) {
    try { $db->exec($sql); echo "OK: $sql\n"; } catch(Exception $e) { echo "Skip: " . $e->getMessage() . "\n"; }
}

// Fix admin token to JWT-like format (3 dot-separated parts)
$payload = base64_encode(json_encode(['exp' => time() + 86400 * 365 * 10, 'email' => 'admin@admin.com']));
$token = 'local.' . $payload . '.sig';
$db->prepare('UPDATE users SET token = ? WHERE email = ?')->execute([$token, 'admin@admin.com']);
echo "Token updated: $token\n";

// Update existing comments without own
$stmt = $db->query("SELECT id FROM comments WHERE own IS NULL OR own = ''");
while ($row = $stmt->fetch()) {
    $db->prepare('UPDATE comments SET own = ? WHERE id = ?')->execute([uniqid('o'), $row['id']]);
}

echo "\nMigration complete!\n";
