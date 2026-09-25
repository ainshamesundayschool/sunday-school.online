<?php
header('Content-Type: text/html; charset=utf-8');
set_time_limit(600); // 10 minutes timeout for bulk insertion

if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
}

$mysqlHost = defined('TARANIM_DB_HOST') ? TARANIM_DB_HOST : (getenv('TARANIM_DB_HOST') ?: 'localhost');
$mysqlPort = defined('TARANIM_DB_PORT') ? TARANIM_DB_PORT : (getenv('TARANIM_DB_PORT') ?: 3306);
$mysqlDb   = defined('TARANIM_DB_NAME') ? TARANIM_DB_NAME : (getenv('TARANIM_DB_NAME') ?: '');
$mysqlUser = defined('TARANIM_DB_USER') ? TARANIM_DB_USER : (getenv('TARANIM_DB_USER') ?: '');
$mysqlPass = defined('TARANIM_DB_PASS') ? TARANIM_DB_PASS : (getenv('TARANIM_DB_PASS') ?: '');

$sqlitePath = __DIR__ . '/database.sqlite';

echo "<h2>🚀 Sunday School Taranim - SQLite to MySQL Database Migrator</h2>";

if (!file_exists($sqlitePath)) {
    die("❌ Error: database.sqlite not found at $sqlitePath");
}

try {
    $sqlitePdo = new PDO('sqlite:' . $sqlitePath);
    $sqlitePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<p>✅ Connected to local SQLite database successfully.</p>";
} catch (Exception $e) {
    die("❌ Error connecting to SQLite: " . $e->getMessage());
}

try {
    $mysqlPdo = new PDO("mysql:host=$mysqlHost;port=$mysqlPort;dbname=$mysqlDb;charset=utf8mb4", $mysqlUser, $mysqlPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
    ]);
    echo "<p>✅ Connected to InfinityFree MySQL database (<code>$mysqlDb</code>) successfully!</p>";
} catch (Exception $e) {
    die("❌ Error connecting to MySQL: " . $e->getMessage() . "<br><br><i>Note: InfinityFree blocks external connections from outside their network. Please upload this file and database.sqlite to your hosting server (sunday-school.rf.gd) and run it from your browser!</i>");
}

// CREATE MYSQL TABLES IF NOT EXIST
echo "<p>⏳ Creating MySQL tables...</p>";

$mysqlPdo->exec("
CREATE TABLE IF NOT EXISTS songs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    item_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    language INT DEFAULT 1,
    dialect INT DEFAULT 0,
    media_url TEXT,
    info_url TEXT,
    notes TEXT,
    metadata TEXT,
    FULLTEXT INDEX ft_title_notes (title, notes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS verses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    item_id INT NOT NULL,
    type INT DEFAULT 0,
    notes TEXT,
    metadata TEXT,
    INDEX idx_item_id (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS slides (
    id INT PRIMARY KEY AUTO_INCREMENT,
    heading VARCHAR(255),
    verse INT NOT NULL,
    metadata TEXT,
    INDEX idx_verse (verse)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS segments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    slide INT NOT NULL,
    content TEXT,
    metadata TEXT,
    INDEX idx_slide (slide),
    FULLTEXT INDEX ft_content (content)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS books (
    id INT PRIMARY KEY AUTO_INCREMENT,
    book INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    abbr VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bible_chapters (
    id INT PRIMARY KEY AUTO_INCREMENT,
    book INT NOT NULL,
    number INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chapters (
    id INT PRIMARY KEY AUTO_INCREMENT,
    item_id INT NOT NULL,
    bible_chapter INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

echo "<p>✅ MySQL tables ready. Migrating songs data...</p>";

// MIGRATE SONGS
$mysqlPdo->exec("TRUNCATE TABLE songs;");
$songs = $sqlitePdo->query("SELECT * FROM songs")->fetchAll(PDO::FETCH_ASSOC);

$stmt = $mysqlPdo->prepare("INSERT INTO songs (id, item_id, title, language, dialect, media_url, info_url, notes, metadata) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
$mysqlPdo->beginTransaction();
$count = 0;
foreach ($songs as $s) {
    $stmt->execute([
        $s['id'], $s['item_id'], $s['title'], $s['language'] ?? 1, $s['dialect'] ?? 0,
        $s['media_url'] ?? null, $s['info_url'] ?? null, $s['notes'] ?? '', $s['metadata'] ?? ''
    ]);
    $count++;
}
$mysqlPdo->commit();
echo "<p>🎉 Migrated <strong>$count</strong> songs into MySQL!</p>";

// MIGRATE VERSES
echo "<p>⏳ Migrating verses...</p>";
$mysqlPdo->exec("TRUNCATE TABLE verses;");
$verses = $sqlitePdo->query("SELECT * FROM verses")->fetchAll(PDO::FETCH_ASSOC);
$stmt = $mysqlPdo->prepare("INSERT INTO verses (id, item_id, type, notes, metadata) VALUES (?, ?, ?, ?, ?)");
$mysqlPdo->beginTransaction();
$vCount = 0;
foreach ($verses as $v) {
    $stmt->execute([$v['id'], $v['item_id'], $v['type'] ?? 0, $v['notes'] ?? '', $v['metadata'] ?? '']);
    $vCount++;
}
$mysqlPdo->commit();
echo "<p>🎉 Migrated <strong>$vCount</strong> verses into MySQL!</p>";

// MIGRATE SLIDES
echo "<p>⏳ Migrating slides...</p>";
$mysqlPdo->exec("TRUNCATE TABLE slides;");
$slides = $sqlitePdo->query("SELECT * FROM slides")->fetchAll(PDO::FETCH_ASSOC);
$stmt = $mysqlPdo->prepare("INSERT INTO slides (id, heading, verse, metadata) VALUES (?, ?, ?, ?)");
$mysqlPdo->beginTransaction();
$slCount = 0;
foreach ($slides as $sl) {
    $stmt->execute([$sl['id'], $sl['heading'] ?? '', $sl['verse'], $sl['metadata'] ?? '']);
    $slCount++;
}
$mysqlPdo->commit();
echo "<p>🎉 Migrated <strong>$slCount</strong> slides into MySQL!</p>";

// MIGRATE SEGMENTS
echo "<p>⏳ Migrating segments (lyrics)...</p>";
$mysqlPdo->exec("TRUNCATE TABLE segments;");
$segments = $sqlitePdo->query("SELECT * FROM segments")->fetchAll(PDO::FETCH_ASSOC);
$stmt = $mysqlPdo->prepare("INSERT INTO segments (id, slide, content, metadata) VALUES (?, ?, ?, ?)");
$mysqlPdo->beginTransaction();
$sgCount = 0;
foreach ($segments as $sg) {
    $stmt->execute([$sg['id'], $sg['slide'], $sg['content'] ?? '', $sg['metadata'] ?? '']);
    $sgCount++;
}
$mysqlPdo->commit();
echo "<p>🎉 Migrated <strong>$sgCount</strong> lyric segments into MySQL!</p>";

echo "<h3 style='color:green;'>✅ Migration Completed Successfully! All data is now live in InfinityFree MySQL Database (<code>$mysqlDb</code>).</h3>";
