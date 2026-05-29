<?php

declare(strict_types=1);

namespace InfraSe\ExcavatorDispatch\Integrations;

use InfraSe\ExcavatorDispatch\Support\Cache;
use InfraSe\ExcavatorDispatch\Support\Settings;
use RuntimeException;

final class GraphExcelClient
{
    private const TOKEN_TRANSIENT = 'graph_token';
    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

    public function fetch_rows(): array
    {
        $s = Settings::all();
        foreach (['m365_tenant_id', 'm365_client_id', 'm365_client_secret'] as $req) {
            if (empty($s[$req])) {
                throw new RuntimeException("Microsoft 365: '{$req}' non configurato.");
            }
        }
        $drive_id = (string) ($s['m365_drive_id'] ?? '');
        $item_id  = (string) ($s['m365_item_id'] ?? '');
        $file_path = (string) ($s['m365_file_path'] ?? '');
        $worksheet = (string) ($s['m365_worksheet'] ?? 'Sheet1');
        $table_name = (string) ($s['m365_table_name'] ?? '');

        if ($drive_id === '' || ($item_id === '' && $file_path === '')) {
            throw new RuntimeException('Configurare drive_id e (item_id o file_path) per il file Excel.');
        }

        $token = $this->access_token();

        if ($item_id === '') {
            $item_id = $this->resolve_item_id($token, $drive_id, $file_path);
        }

        if ($table_name !== '') {
            $url = self::GRAPH_BASE . "/drives/{$drive_id}/items/{$item_id}/workbook/tables/" . rawurlencode($table_name) . '/rows?$top=999';
            return $this->fetch_table_rows($token, $url, $table_name, $drive_id, $item_id);
        }

        return $this->fetch_used_range($token, $drive_id, $item_id, $worksheet);
    }

    private function access_token(): string
    {
        return Cache::remember(self::TOKEN_TRANSIENT, 3000, function () {
            $s = Settings::all();
            $resp = wp_remote_post("https://login.microsoftonline.com/{$s['m365_tenant_id']}/oauth2/v2.0/token", [
                'timeout' => 20,
                'body' => [
                    'client_id'     => $s['m365_client_id'],
                    'client_secret' => $s['m365_client_secret'],
                    'grant_type'    => 'client_credentials',
                    'scope'         => 'https://graph.microsoft.com/.default',
                ],
            ]);
            if (is_wp_error($resp)) {
                throw new RuntimeException('Graph token: ' . $resp->get_error_message());
            }
            $code = (int) wp_remote_retrieve_response_code($resp);
            $body = (string) wp_remote_retrieve_body($resp);
            $data = json_decode($body, true);
            if (!is_array($data) || empty($data['access_token'])) {
                $err = is_array($data) && !empty($data['error']) ? $data['error'] : 'unknown';
                $desc = is_array($data) && !empty($data['error_description']) ? $data['error_description'] : $body;
                throw new RuntimeException("Graph token [{$code}] {$err}: {$desc}");
            }
            return (string) $data['access_token'];
        });
    }

    private function resolve_item_id(string $token, string $drive_id, string $path): string
    {
        $path = ltrim($path, '/');
        $url = self::GRAPH_BASE . "/drives/{$drive_id}/root:/" . rawurlencode($path) . ':';
        // rawurlencode encodes slashes too; restore them for path segments.
        $url = str_replace('%2F', '/', $url);
        $data = $this->graph_get($token, $url);
        if (empty($data['id'])) {
            throw new RuntimeException('Graph: file non trovato al percorso ' . $path);
        }
        return (string) $data['id'];
    }

    private function fetch_table_rows(string $token, string $url, string $table, string $drive_id, string $item_id): array
    {
        $headers = $this->fetch_table_headers($token, $drive_id, $item_id, $table);
        $data = $this->graph_get($token, $url);
        $rows = [];
        foreach ((array) ($data['value'] ?? []) as $i => $entry) {
            $values = $entry['values'][0] ?? [];
            $assoc = [];
            foreach ($headers as $col => $header) {
                $assoc[$header] = $values[$col] ?? null;
            }
            $assoc['__row'] = $i;
            $rows[] = $assoc;
        }
        return $rows;
    }

    private function fetch_table_headers(string $token, string $drive_id, string $item_id, string $table): array
    {
        $url = self::GRAPH_BASE . "/drives/{$drive_id}/items/{$item_id}/workbook/tables/" . rawurlencode($table) . '/headerRowRange';
        $data = $this->graph_get($token, $url);
        return (array) ($data['values'][0] ?? []);
    }

    private function fetch_used_range(string $token, string $drive_id, string $item_id, string $worksheet): array
    {
        $url = self::GRAPH_BASE . "/drives/{$drive_id}/items/{$item_id}/workbook/worksheets('" . rawurlencode($worksheet) . "')/usedRange";
        $data = $this->graph_get($token, $url);
        $values = (array) ($data['values'] ?? []);
        if (empty($values)) {
            return [];
        }
        $headers = array_map('strval', array_shift($values));
        $rows = [];
        foreach ($values as $i => $line) {
            $assoc = [];
            foreach ($headers as $col => $header) {
                $assoc[$header] = $line[$col] ?? null;
            }
            $assoc['__row'] = $i;
            $rows[] = $assoc;
        }
        return $rows;
    }

    private function graph_get(string $token, string $url): array
    {
        $resp = wp_remote_get($url, [
            'timeout' => 20,
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
        ]);
        if (is_wp_error($resp)) {
            throw new RuntimeException('Graph GET: ' . $resp->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = (string) wp_remote_retrieve_body($resp);
        if ($code < 200 || $code >= 300) {
            $short_url = preg_replace('#^https://graph\.microsoft\.com/v1\.0#', '', $url);
            throw new RuntimeException("Graph GET {$code} on {$short_url} :: {$body}");
        }
        $data = json_decode($body, true);
        return is_array($data) ? $data : [];
    }
}
