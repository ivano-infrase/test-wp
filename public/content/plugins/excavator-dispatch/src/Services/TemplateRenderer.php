<?php

declare(strict_types=1);

namespace InfraSe\ExcavatorDispatch\Services;

use InfraSe\ExcavatorDispatch\Support\Settings;

/**
 * Builds the components[] payload for a WhatsApp template call.
 *
 * The mapping configured in settings ties each template variable to an
 * expression that is evaluated against the list of selected machines.
 *
 * Supported expressions:
 *  - {col:Name}                  -> first machine's "Name" column
 *  - {list:Modello,Anno}         -> bullet list joining the given columns for every selected machine
 *  - {count}                     -> number of selected machines
 *  - literal text                -> used as-is
 */
final class TemplateRenderer
{
    public function build_components(string $template_name, array $machines): array
    {
        $mappings = (array) Settings::get('template_mappings', []);
        $config = $mappings[$template_name] ?? null;
        if (!is_array($config) || empty($config)) {
            return [];
        }

        $components = [];

        foreach (['header', 'body', 'footer'] as $type) {
            if (empty($config[$type]) || !is_array($config[$type])) {
                continue;
            }
            $params = [];
            foreach ($config[$type] as $expr) {
                $params[] = [
                    'type' => 'text',
                    'text' => $this->evaluate((string) $expr, $machines),
                ];
            }
            if (!empty($params)) {
                $components[] = [
                    'type'       => $type,
                    'parameters' => $params,
                ];
            }
        }
        return $components;
    }

    public function evaluate(string $expression, array $machines): string
    {
        $expression = (string) $expression;
        $expression = preg_replace_callback('/\{count\}/', static function () use ($machines): string {
            return (string) count($machines);
        }, $expression) ?? $expression;

        $expression = preg_replace_callback('/\{col:([^}]+)\}/u', static function (array $m) use ($machines): string {
            $col = trim($m[1]);
            return (string) ($machines[0][$col] ?? '');
        }, $expression) ?? $expression;

        $expression = preg_replace_callback('/\{list:([^}]+)\}/u', static function (array $m) use ($machines): string {
            $cols = array_map('trim', explode(',', $m[1]));
            $lines = [];
            foreach ($machines as $row) {
                $parts = [];
                foreach ($cols as $c) {
                    $v = (string) ($row[$c] ?? '');
                    if ($v !== '') {
                        $parts[] = $v;
                    }
                }
                if (!empty($parts)) {
                    $lines[] = '- ' . implode(' ', $parts);
                }
            }
            return implode("\n", $lines);
        }, $expression) ?? $expression;

        return $expression;
    }
}
