<?php
// Loads .env via vlucas/phpdotenv and exposes config() + printers().
// Real environment variables always win over .env (createImmutable).

require_once __DIR__ . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();

function config(): array
{
    return [
        'cache_ttl'    => (int)    ($_ENV['CACHE_TTL']    ?? 30),
        'poll_timeout' => (int)    ($_ENV['POLL_TIMEOUT'] ?? 10),
        'ipp_port'     => (int)    ($_ENV['IPP_PORT']     ?? 631),
        'ipp_path'     => (string) ($_ENV['IPP_PATH']     ?? '/ipp/print'),
    ];
}

function printers(): array
{
    $out = [];
    for ($i = 1; $i <= 100; $i++) {
        $name = $_ENV["PRINTER_{$i}_NAME"] ?? null;
        $ip   = $_ENV["PRINTER_{$i}_IP"]   ?? null;
        if ($name && $ip) {
            $out[] = [
                'id'   => substr(md5("{$name}|{$ip}"), 0, 12),
                'name' => $name,
                'ip'   => $ip,
            ];
        }
    }
    return $out;
}
