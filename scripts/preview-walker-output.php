<?php
/**
 * Simulate the new Layout_Walker output by replaying its schema logic
 * over a dumped layout JSON. Lets us preview the inspector results
 * without booting WordPress.
 */

declare(strict_types=1);

$path = $argv[1] ?? __DIR__ . '/../docs/layout-dumps/post-913.json';
$data = json_decode(file_get_contents($path), true);
$layout = $data['meta']['_fl_builder_data'];

$SCHEMAS = [
    'heading' => [
        'fields' => [
            ['key' => 'heading', 'kind' => 'html'],
            ['key' => 'link',    'kind' => 'url'],
        ],
    ],
    'rich-text' => ['fields' => [['key' => 'text', 'kind' => 'html']]],
    'html'      => ['fields' => [['key' => 'html', 'kind' => 'html']]],
    'button' => [
        'fields' => [
            ['key' => 'text', 'kind' => 'text'],
            ['key' => 'link', 'kind' => 'url'],
        ],
    ],
    'icon' => [
        'fields' => [
            ['key' => 'text',    'kind' => 'html'],
            ['key' => 'sr_text', 'kind' => 'text'],
            ['key' => 'link',    'kind' => 'url'],
        ],
    ],
    'photo' => [
        'fields' => [
            ['key' => 'photo',     'kind' => 'image_id'],
            ['key' => 'photo_src', 'kind' => 'image_url'],
            ['key' => 'photo_url', 'kind' => 'image_url'],
            ['key' => 'caption',   'kind' => 'html'],
            ['key' => 'data',      'kind' => 'image_meta'],
            ['key' => 'link_url',  'kind' => 'url'],
        ],
    ],
    'image-icon' => [
        'image_type' => ['key' => 'image_type', 'photo_value' => 'photo'],
        'fields' => [
            ['key' => 'photo',     'kind' => 'image_id',   'requires_photo_mode' => true],
            ['key' => 'photo_src', 'kind' => 'image_url',  'requires_photo_mode' => true],
            ['key' => 'data',      'kind' => 'image_meta', 'requires_photo_mode' => true],
        ],
    ],
    'separator'  => ['fields' => []],
    'icon-group' => ['fields' => []],
    'button-group' => [
        'fields'      => [],
        'items'       => 'items',
        'item_fields' => [
            ['key' => 'text', 'kind' => 'text'],
            ['key' => 'link', 'kind' => 'url'],
        ],
    ],
    'list-icon' => [
        'fields'      => [],
        'items'       => 'list_items',
        'item_fields' => [['key' => 'title', 'kind' => 'text']],
    ],
    'uabb-faq' => [
        'fields'      => [],
        'items'       => 'faq_items',
        'item_fields' => [
            ['key' => 'faq_question', 'kind' => 'text'],
            ['key' => 'faq_answer',   'kind' => 'html'],
        ],
    ],
    'uabb-image-carousel' => ['fields' => []],
];

$summary = ['modules' => 0, 'globals' => 0, 'fields' => 0, 'unknown' => 0, 'by_kind' => []];
$samples = [];
$unknown_modules = [];

foreach ($layout as $node_id => $node) {
    if (($node['type'] ?? '') !== 'module') continue;
    $summary['modules']++;
    $is_global = !empty($node['template_id']) || !empty($node['global']);
    if ($is_global) { $summary['globals']++; continue; }

    $slug = $node['settings']['type'] ?? '?';
    if (!isset($SCHEMAS[$slug])) {
        $summary['unknown']++;
        $unknown_modules[$slug] = ($unknown_modules[$slug] ?? 0) + 1;
        continue;
    }
    $schema = $SCHEMAS[$slug];
    $fields = extract_with_schema((object)$node['settings'], $schema);

    foreach ($fields as $f) {
        $summary['fields']++;
        $summary['by_kind'][$f['kind']] = ($summary['by_kind'][$f['kind']] ?? 0) + 1;
        if (count($samples[$slug] ?? []) < 3) {
            $samples[$slug][] = $f;
        }
    }
}

echo "=== WALKER PREVIEW for {$data['post']['post_title']} ===\n\n";
echo "Modules total: {$summary['modules']}\n";
echo "Globals (skipped): {$summary['globals']}\n";
echo "Unknown module types: {$summary['unknown']}\n";
if (!empty($unknown_modules)) print_r($unknown_modules);
echo "Total content fields surfaced: {$summary['fields']}\n";
echo "By kind:\n"; print_r($summary['by_kind']);

echo "\n=== SAMPLE OUTPUT BY MODULE TYPE ===\n";
foreach ($samples as $slug => $items) {
    echo "\n## {$slug}\n";
    foreach ($items as $f) {
        $val = $f['value'];
        if (strlen($val) > 120) $val = substr($val, 0, 117) . '...';
        printf("  [%s] %s = %s\n", $f['kind'], $f['path'], preg_replace('/\s+/', ' ', $val));
    }
}

function extract_with_schema(object $settings, array $schema): array
{
    $out = [];
    $is_photo_mode = true;
    if (isset($schema['image_type'])) {
        $mode_key    = $schema['image_type']['key'];
        $photo_value = $schema['image_type']['photo_value'];
        $is_photo_mode = isset($settings->$mode_key) && (string)$settings->$mode_key === $photo_value;
    }

    foreach (($schema['fields'] ?? []) as $fd) {
        if (!empty($fd['requires_photo_mode']) && !$is_photo_mode) continue;
        $key = $fd['key'];
        if (!isset($settings->$key)) continue;
        $entry = build_entry($key, $fd['kind'], $settings->$key);
        if ($entry !== null) $out[] = $entry;
    }
    if (isset($schema['items'], $schema['item_fields'])) {
        $items_key = $schema['items'];
        $items = $settings->$items_key ?? null;
        if (is_array($items)) {
            foreach ($items as $idx => $item) {
                $item = is_object($item) ? (array)$item : (is_array($item) ? $item : []);
                foreach ($schema['item_fields'] as $fd) {
                    $key = $fd['key'];
                    if (!array_key_exists($key, $item)) continue;
                    $entry = build_entry("{$items_key}.{$idx}.{$key}", $fd['kind'], $item[$key]);
                    if ($entry !== null) $out[] = $entry;
                }
            }
        }
    }
    return $out;
}

function build_entry(string $path, string $kind, $value): ?array
{
    switch ($kind) {
        case 'image_id':
            if (is_int($value) || (is_string($value) && ctype_digit((string)$value))) {
                $id = (int)$value;
                return $id > 0 ? ['path'=>$path,'kind'=>$kind,'value'=>(string)$id] : null;
            }
            return null;
        case 'image_url':
        case 'url':
            if (!is_string($value)) return null;
            $s = trim($value);
            return $s === '' ? null : ['path'=>$path,'kind'=>$kind,'value'=>$s];
        case 'image_meta':
            $m = is_object($value) ? (array)$value : (is_array($value) ? $value : null);
            if (!is_array($m)) return null;
            $sum = [];
            foreach (['title','alt','caption','description','filename','url','name'] as $k) {
                if (isset($m[$k]) && is_string($m[$k]) && trim($m[$k]) !== '') $sum[$k] = $m[$k];
            }
            return empty($sum) ? null : ['path'=>$path,'kind'=>$kind,'value'=>json_encode($sum)];
        case 'text':
        case 'html':
            if (!is_string($value)) return null;
            $s = trim($value);
            if ($s === '') return null;
            if (!preg_match('/[A-Za-z]{2,}/', $s)) return null;
            $plain = trim(strip_tags($s));
            if ($kind === 'text' && strlen($plain) < 1) return null;
            if ($kind === 'html' && strlen($plain) < 2) return null;
            return ['path'=>$path,'kind'=>$kind,'value'=>$s];
    }
    return null;
}
