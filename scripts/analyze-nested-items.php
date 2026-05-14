<?php
/**
 * Inspect the shape of nested item arrays inside complex BB modules.
 *
 * Specifically: button-group items, list-icon list_items, uabb-faq faq_items,
 * icon-group icons. We need to know the exact field names of each item to
 * build a precise per-module schema.
 */

declare(strict_types=1);

$path = $argv[1] ?? __DIR__ . '/../docs/layout-dumps/post-913.json';
$data = json_decode(file_get_contents($path), true);
$layout = $data['meta']['_fl_builder_data'];

$nested_specs = [
    'button-group'      => 'items',
    'list-icon'         => 'list_items',
    'uabb-faq'          => 'faq_items',
    'icon-group'        => 'icons',
];

foreach ($nested_specs as $module_type => $items_key) {
    foreach ($layout as $node) {
        if (($node['type'] ?? '') !== 'module') continue;
        if (!empty($node['template_id'])) continue;
        if (($node['settings']['type'] ?? '') !== $module_type) continue;
        $items = $node['settings'][$items_key] ?? null;
        if (!is_array($items) || empty($items)) continue;

        echo "\n=== {$module_type}.{$items_key} (sample item) ===\n";
        $sample = reset($items);
        if (is_array($sample) || is_object($sample)) {
            $sample = (array) $sample;
            foreach ($sample as $k => $v) {
                printf("  %-32s %-8s %s\n", $k, gettype($v), preview($v));
            }
        }
        break;
    }
}

function preview($v, int $max = 200): string
{
    if (is_string($v)) {
        $v = preg_replace('/\s+/', ' ', $v);
        return strlen($v) > $max ? '"' . substr($v, 0, $max) . '..."' : '"' . $v . '"';
    }
    if (is_bool($v)) return $v ? 'true' : 'false';
    if (is_array($v)) return 'array(' . count($v) . ')';
    if (is_object($v)) return 'object';
    if (is_null($v)) return 'null';
    return (string) $v;
}
