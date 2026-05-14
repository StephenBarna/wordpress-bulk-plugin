<?php
/**
 * Dump a Beaver Builder layout to JSON for inspection.
 *
 * Usage:
 *   php scripts/dump-bb-layout.php <post_id> [out_path]
 *
 * Connects to LocalWP's MySQL socket directly. Reads `_fl_builder_data`
 * from wp_postmeta, unserializes the PHP-serialized layout, and writes
 * pretty-printed JSON to disk so we can study the actual node + settings
 * structure to refine the layout walker.
 */

declare(strict_types=1);

$post_id  = isset($argv[1]) ? (int) $argv[1] : 913;
$out_path = $argv[2] ?? __DIR__ . "/../docs/layout-dumps/post-{$post_id}.json";

$socket = '/Users/stephenbarna/Library/Application Support/Local/run/IrMKR609G/mysql/mysqld.sock';
$db     = 'local';
$user   = 'root';
$pass   = 'root';

$mysqli = new mysqli('localhost', $user, $pass, $db, 0, $socket);
if ($mysqli->connect_errno) {
    fwrite(STDERR, "MySQL connect failed: {$mysqli->connect_error}\n");
    exit(1);
}
$mysqli->set_charset('utf8mb4');

$keys = ['_fl_builder_data', '_fl_builder_draft', '_fl_builder_data_settings', '_fl_builder_draft_settings'];
$bag  = ['post_id' => $post_id, 'meta' => []];

$post_row = $mysqli->query("SELECT post_title, post_status, post_type FROM wp_posts WHERE ID = {$post_id}")->fetch_assoc();
$bag['post'] = $post_row;

foreach ($keys as $key) {
    $stmt = $mysqli->prepare('SELECT meta_value FROM wp_postmeta WHERE post_id = ? AND meta_key = ? LIMIT 1');
    $stmt->bind_param('is', $post_id, $key);
    $stmt->execute();
    $stmt->bind_result($val);
    $found = false;
    while ($stmt->fetch()) {
        $found = true;
        $unserialized = @unserialize($val);
        $bag['meta'][$key] = $unserialized !== false ? $unserialized : $val;
    }
    if (!$found) {
        $bag['meta'][$key] = null;
    }
    $stmt->close();
}

$dir = dirname($out_path);
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

$json = json_encode($bag, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
if ($json === false) {
    fwrite(STDERR, "JSON encode failed: " . json_last_error_msg() . "\n");
    exit(1);
}

file_put_contents($out_path, $json);

$bytes = filesize($out_path);
echo "Wrote {$bytes} bytes to {$out_path}\n";

$mysqli->close();
