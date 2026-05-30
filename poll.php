<?php
// Live single-printer poll endpoint, cached server-side for cache_ttl seconds.
//
// HTTP: GET poll.php?id=<id-or-ip>
// CLI:  php poll.php <id-or-ip>     (always bypasses cache)

require_once __DIR__ . '/config.php';

use obray\ipp\Printer as IppPrinter;

$cfg = config();
$arg = $argv[1] ?? $_GET['id'] ?? null;
$cli = PHP_SAPI === 'cli';

if (!$cli) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
}

if (!$arg) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Missing id']));
}

$printer = null;
foreach (printers() as $p) {
    if ($p['id'] === $arg || $p['ip'] === $arg) { $printer = $p; break; }
}
if (!$printer) {
    http_response_code(404);
    exit(json_encode(['ok' => false, 'error' => 'Printer not found']));
}

$snap = poll_with_cache($printer, $cfg, $cli);

echo json_encode(
    ['id' => $printer['id'], 'name' => $printer['name'], 'ip' => $printer['ip']] + $snap,
    JSON_UNESCAPED_SLASHES | ($cli ? JSON_PRETTY_PRINT : 0)
);
if ($cli) echo "\n";

// ===========================================================================
// Cache + poll
// ===========================================================================

function poll_with_cache(array $printer, array $cfg, bool $bypass): array
{
    $path = __DIR__ . '/data/cache/' . $printer['id'] . '.json';

    if (!$bypass && is_file($path) && time() - filemtime($path) < $cfg['cache_ttl']) {
        $cached = json_decode((string) @file_get_contents($path), true);
        if (is_array($cached)) return $cached;
    }

    $snap = [
        'polled_at'     => time(),
        'online'        => false,
        'status'        => null,
        'state_reasons' => [],
        'markers'       => [],
        'device_name'   => null,
        'error'         => null,
    ];
    try {
        $snap = array_merge($snap, poll_ipp($printer['ip'], $cfg));
    } catch (Throwable $e) {
        $snap['error'] = $e->getMessage();
    }

    @mkdir(dirname($path), 0755, true);
    @file_put_contents($path . '.tmp', json_encode($snap, JSON_UNESCAPED_SLASHES));
    @rename($path . '.tmp', $path);

    return $snap;
}

// ===========================================================================
// IPP - via obray/ipp
// ===========================================================================

function poll_ipp(string $host, array $cfg): array
{
    $uri = sprintf('ipp://%s:%d%s', $host, $cfg['ipp_port'], $cfg['ipp_path']);

    $curlOpts = [
        ['key' => CURLOPT_CONNECTTIMEOUT, 'value' => $cfg['poll_timeout']],
        ['key' => CURLOPT_TIMEOUT,        'value' => $cfg['poll_timeout']],
    ];

    $response = (new IppPrinter($uri, '', '', $curlOpts))
        ->getPrinterAttributes(1, [
            'printer-state',
            'printer-state-reasons',
            'printer-name',
            'printer-make-and-model',
            'marker-names',
            'marker-colors',
            'marker-levels',
            'marker-high-levels',
        ]);

    // printerAttributes is a list of attribute groups (IPP allows several).
    // We use the first group, which is what every printer we've seen returns.
    $group = $response->printerAttributes[0] ?? null;
    if (!$group instanceof \obray\ipp\PrinterAttributes) {
        throw new RuntimeException('IPP response had no printer-attributes group');
    }
    return ipp_snapshot($group);
}

function ipp_snapshot(\obray\ipp\PrinterAttributes $attrs): array
{
    $state = ipp_get($attrs, 'printer-state');
    $stateLabel = match (is_int($state) ? $state : null) {
        3 => 'idle', 4 => 'processing', 5 => 'stopped', default => 'unknown',
    };

    $reasons = array_values(array_filter(
        ipp_list($attrs, 'printer-state-reasons'),
        fn($r) => $r !== '' && $r !== 'none'
    ));

    $names  = ipp_list($attrs, 'marker-names');
    $colors = ipp_list($attrs, 'marker-colors');
    $levels = ipp_list($attrs, 'marker-levels');
    $highs  = ipp_list($attrs, 'marker-high-levels');

    $markers = [];
    for ($i = 0, $n = max(count($names), count($levels)); $i < $n; $i++) {
        $level = isset($levels[$i]) ? (int) $levels[$i] : null;
        $max   = isset($highs[$i])  ? (int) $highs[$i]  : 100;
        $markers[] = [
            'name'    => trim((string) ($names[$i] ?? 'Marker ' . ($i + 1))),
            'color'   => marker_color($colors[$i] ?? null, $names[$i] ?? null),
            'percent' => ($level === null || $level < 0 || $max <= 0)
                ? null
                : min(100, (int) round(($level / $max) * 100)),
        ];
    }

    return [
        'online'        => true,
        'status'        => $stateLabel,
        'state_reasons' => $reasons,
        'markers'       => $markers,
        'device_name'   => first_string(
            ipp_get($attrs, 'printer-make-and-model'),
            ipp_get($attrs, 'printer-name')
        ),
    ];
}

// Single-value accessor that tolerates missing attributes and unwraps the
// obray/ipp Attribute object into a plain PHP value.
function ipp_get(\obray\ipp\PrinterAttributes $attrs, string $name): mixed
{
    if (!$attrs->has($name)) return null;
    $a = $attrs->{$name};
    return is_array($a) ? $a[0]->getAttributeValue() : $a->getAttributeValue();
}

// Multi-value accessor; always returns a (possibly empty) list.
function ipp_list(\obray\ipp\PrinterAttributes $attrs, string $name): array
{
    if (!$attrs->has($name)) return [];
    $a = $attrs->{$name};
    return is_array($a)
        ? array_map(fn($x) => $x->getAttributeValue(), $a)
        : [$a->getAttributeValue()];
}

function first_string(...$candidates): ?string
{
    foreach ($candidates as $c) {
        if (is_string($c) && trim($c) !== '') return trim($c);
    }
    return null;
}

function marker_color(?string $color, ?string $hint): string
{
    static $named = [
        'black'   => '#1a1a1a',
        'cyan'    => '#00b8d4',
        'magenta' => '#d500a0',
        'yellow'  => '#f2c200',
        'red'     => '#d33',
        'green'   => '#3a3',
        'blue'    => '#2962ff',
    ];
    foreach ([$color, $hint] as $c) {
        if (!is_string($c) || $c === '') continue;
        $c = strtolower(trim($c));
        if (preg_match('/^#?[0-9a-f]{6}$/', $c)) return $c[0] === '#' ? $c : "#{$c}";
        foreach ($named as $key => $hex) {
            if (str_contains($c, $key)) return $hex;
        }
    }
    return '#777';
}
