<?php

declare(strict_types=1);

namespace InfraSe\ExcavatorDispatch\Services;

use InfraSe\ExcavatorDispatch\Support\Settings;

/**
 * Builds the components[] payload for a WhatsApp template call.
 *
 * Mapping shape per template:
 *   "header"/"body"/"footer": either
 *     - sequential array of expressions   -> positional {{1}}, {{2}}, ...
 *     - associative array name=>expression -> named {{name}} placeholders
 *
 * Expressions supported in any value:
 *   {col:Name}         first machine's "Name" column
 *   {list:Col1,Col2}   bullet list joining the columns for every machine
 *   {count}            number of selected machines
 *   {recipient_name}   contact display name
 *   {recipient_phone}  contact phone (E.164)
 *   {recipient_org}    contact organization
 *   literal text       used as-is
 */
final class TemplateRenderer
{
    public function build_components(string $template_name, array $machines, array $recipient = []): array
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
            $is_named = $this->is_assoc($config[$type]);
            $params = [];
            foreach ($config[$type] as $key => $expr) {
                $param = [
                    'type' => 'text',
                    'text' => $this->evaluate((string) $expr, $machines, $recipient),
                ];
                if ($is_named) {
                    $param['parameter_name'] = (string) $key;
                }
                $params[] = $param;
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

    public function evaluate(string $expression, array $machines, array $recipient = []): string
    {
        $expression = (string) $expression;

        $expression = preg_replace_callback('/\{count\}/', static function () use ($machines): string {
            return (string) count($machines);
        }, $expression) ?? $expression;

        $expression = preg_replace_callback('/\{recipient_name\}/u', static function () use ($recipient): string {
            return (string) ($recipient['name'] ?? '');
        }, $expression) ?? $expression;

        $expression = preg_replace_callback('/\{recipient_phone\}/u', static function () use ($recipient): string {
            return (string) ($recipient['phone'] ?? '');
        }, $expression) ?? $expression;

        $expression = preg_replace_callback('/\{recipient_org\}/u', static function () use ($recipient): string {
            return (string) ($recipient['organization'] ?? '');
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

    private function is_assoc(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
