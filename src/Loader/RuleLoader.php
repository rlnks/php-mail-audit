<?php

namespace MailAudit\Loader;

class RuleLoader
{
    private string $bundledRulesPath;
    private array $config;

    public function __construct(array $config = [])
    {
        $this->bundledRulesPath = dirname(__DIR__, 2) . '/rules';
        $this->config = array_merge([
            'auto_update' => false,
            'ttl_days'    => 7,
            'endpoint'    => null,
            'api_key'     => null,
            'cache_path'  => null,
        ], $config);
    }

    public function load(): array
    {
        if ($this->config['auto_update'] && $this->config['endpoint']) {
            $cached = $this->loadFromCache();
            if ($cached !== null) {
                return $cached;
            }

            $remote = $this->fetchRemote();
            if ($remote !== null) {
                $this->writeCache($remote);
                return $remote;
            }
        }

        return $this->loadBundled();
    }

    private function loadBundled(): array
    {
        $rules = [];
        foreach (glob($this->bundledRulesPath . '/*.json') as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data) {
                $rules[] = $data;
            }
        }
        return $rules;
    }

    private function loadFromCache(): ?array
    {
        $cachePath = $this->config['cache_path'];
        if (!$cachePath || !file_exists($cachePath)) {
            return null;
        }

        $mtime = filemtime($cachePath);
        $ttlSeconds = ($this->config['ttl_days'] ?? 7) * 86400;
        if (time() - $mtime > $ttlSeconds) {
            return null;
        }

        $data = json_decode(file_get_contents($cachePath), true);
        return is_array($data) ? $data : null;
    }

    private function fetchRemote(): ?array
    {
        $headers = ['Accept: application/json'];
        if ($this->config['api_key']) {
            $headers[] = 'Authorization: Bearer ' . $this->config['api_key'];
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 5,
                'header'  => implode("\r\n", $headers),
            ],
        ]);

        $raw = @file_get_contents($this->config['endpoint'], false, $ctx);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function writeCache(array $rules): void
    {
        $cachePath = $this->config['cache_path'];
        if (!$cachePath) {
            return;
        }

        $dir = dirname($cachePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($cachePath, json_encode($rules, JSON_PRETTY_PRINT));
    }
}
