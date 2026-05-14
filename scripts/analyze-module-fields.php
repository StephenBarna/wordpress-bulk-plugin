<?php
/**
 * Analyze every distinct module type on a dumped layout.
 *
 * For each module slug, picks a NON-global instance and prints its settings
 * keys with abbreviated values, so we can see what is actually content vs
 * structural/style configuration.
 */

declare(strict_types=1);

$path = $argv[1] ?? __DIR__ . '/../docs/layout-dumps/post-913.json';
$out  = $argv[2] ?? __DIR__ . '/../docs/layout-dumps/post-913-field-analysis.txt';

$data   = json_decode(file_get_contents($path), true);
$layout = $data['meta']['_fl_builder_data'];

$samples = [];
foreach ($layout as $node_id => $node) {
    if (($node['type'] ?? '') !== 'module') continue;
    if (!empty($node['template_id'])) continue;
    if (!empty($node['global'])) continue;
    $slug = $node['settings']['type'] ?? '?';
    if (!isset($samples[$slug])) {
        $samples[$slug] = ['id' => $node_id, 'settings' => $node['settings']];
    }
}

$lines = [];
$lines[] = "=== Module field analysis for post {$data['post_id']}: {$data['post']['post_title']} ===";
$lines[] = '';

foreach ($samples as $slug => $sample) {
    $lines[] = "## Module: {$slug}  (sample node: {$sample['id']})";
    $lines[] = '';
    $settings = $sample['settings'];

    $rows = [];
    foreach ($settings as $key => $value) {
        $kind = gettype($value);
        $preview = preview_value($value);
        $rows[] = sprintf('  %-40s %-8s %s', $key, $kind, $preview);
    }
    sort($rows);
    $lines = array_merge($lines, $rows);
    $lines[] = '';
    $lines[] = '';
}

file_put_contents($out, implode("\n", $lines));
echo "Wrote analysis to {$out}\n";
echo "Module types analyzed: " . count($samples) . "\n";

function preview_value($value, int $max = 200): string
{
    if (is_string($value)) {
        $value = preg_replace('/\s+/', ' ', $value);
        if (strlen($value) > $max) {
            return '"' . substr($value, 0, $max) . '..."';
        }
        return '"' . $value . '"';
    }
    if (is_bool($value)) return $value ? 'true' : 'false';
    if (is_int($value) || is_float($value)) return (string) $value;
    if (is_null($value)) return 'null';
    if (is_array($value)) {
        return 'array(' . count($value) . ') keys=[' . implode(',', array_slice(array_map('strval', array_keys($value)), 0, 10)) . ']';
    }
    if (is_object($value)) {
        $arr = (array) $value;
        return 'object{' . count($arr) . '} keys=[' . implode(',', array_slice(array_map('strval', array_keys($arr)), 0, 10)) . ']';
    }
    return gettype($value);
}
