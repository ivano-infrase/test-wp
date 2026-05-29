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
 *   {at:N,Col1,Col2}   N-th machine (0-based), joins given columns with space
 *                      returns "—" when no machine at that index
 *   {list:Col1,Col2}   inline list joined by ' · '
 *   {bullets:Col1,Col2} inline list joined by ' • '
 *   {img:Column}       composes a full URL from the "Image URL pattern"
 *                      setting (placeholder {value}) and the column value
 *   {count}            number of selected machines
 *   {recipient_name}   contact display name
 *   {recipient_phone}  contact phone (E.164)
 *   {recipient_org}    contact organization
 *   literal text       used as-is
 *
 * Carousel templates: add a "carousel" key alongside body/header/footer:
 *   "carousel": {
 *     "header_image": "{img:Foto}",          // header image link expression
 *     "body": { "name": "{col:Modello}" },   // per-card body params
 *     "buttons": [                            // optional, max 2 per card
 *        { "sub_type": "url",         "param": "{col:Matricola}" },
 *        { "sub_type": "quick_reply", "param": "interested-{col:Matricola}" }
 *     ],
 *     "max_cards": 10                         // optional, hard cap 10
 *   }
 *
 * NOTE: WhatsApp Cloud API rejects parameters containing newlines, tabs,
 * or 5+ consecutive spaces. Vertical bulleted lists are not possible
 * inside a single parameter — design the template body accordingly
 * (e.g. put line breaks in the template itself, around the placeholder).
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
            if ($type === 'header' && $this->is_media_header($config[$type])) {
                $media = $this->build_media_header($config[$type], $machines, $recipient);
                if ($media !== null) {
                    $components[] = $media;
                }
                continue;
            }
            $is_named = $this->is_assoc($config[$type]);
            $params = [];
            foreach ($config[$type] as $key => $expr) {
                $param = [
                    'type' => 'text',
                    'text' => self::sanitize_param($this->evaluate((string) $expr, $machines, $recipient)),
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

        if (!empty($config['buttons']) && is_array($config['buttons'])) {
            foreach ($this->build_buttons($config['buttons'], $machines, $recipient) as $btn) {
                $components[] = $btn;
            }
        }

        if (!empty($config['carousel']) && is_array($config['carousel'])) {
            $carousel = $this->build_carousel($config['carousel'], $machines, $recipient);
            if ($carousel !== null) {
                $components[] = $carousel;
            }
        }

        return $components;
    }

    private function is_media_header(array $cfg): bool
    {
        foreach (['image', 'video', 'document'] as $k) {
            if (array_key_exists($k, $cfg)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Builds button components for a non-carousel template.
     *
     * Each button entry shape:
     *   { "sub_type": "url"|"quick_reply"|"copy_code", "param": "...", "index": 0 }
     *
     * Static buttons (URL without variable, Phone, plain Quick Reply) don't
     * need an entry here — Meta resolves them from the template definition.
     */
    private function build_buttons(array $buttons, array $machines, array $recipient): array
    {
        $out = [];
        $auto_index = 0;
        foreach ($buttons as $key => $btn) {
            if (!is_array($btn) || empty($btn['sub_type'])) {
                $auto_index++;
                continue;
            }
            $sub = (string) $btn['sub_type'];
            $index = isset($btn['index']) ? (int) $btn['index'] : (is_int($key) ? $key : $auto_index);
            $value = self::sanitize_param($this->evaluate((string) ($btn['param'] ?? ''), $machines, $recipient));

            switch ($sub) {
                case 'quick_reply':
                    $param = ['type' => 'payload', 'payload' => $value];
                    break;
                case 'copy_code':
                    $param = ['type' => 'coupon_code', 'coupon_code' => $value];
                    break;
                case 'url':
                default:
                    $param = ['type' => 'text', 'text' => $value];
                    break;
            }
            $out[] = [
                'type'       => 'button',
                'sub_type'   => $sub,
                'index'      => (string) $index,
                'parameters' => [$param],
            ];
            $auto_index++;
        }
        return $out;
    }

    private function build_media_header(array $cfg, array $machines, array $recipient): ?array
    {
        foreach (['image', 'video', 'document'] as $kind) {
            if (empty($cfg[$kind])) {
                continue;
            }
            $link = trim($this->evaluate((string) $cfg[$kind], $machines, $recipient));
            if ($link === '') {
                return null;
            }
            return [
                'type'       => 'header',
                'parameters' => [[
                    'type' => $kind,
                    $kind  => ['link' => $link],
                ]],
            ];
        }
        return null;
    }

    private function build_carousel(array $cfg, array $machines, array $recipient): ?array
    {
        $max = max(1, min(10, (int) ($cfg['max_cards'] ?? 10)));
        $selected = array_slice($machines, 0, $max);
        if ($selected === []) {
            return null;
        }
        $cards = [];
        foreach ($selected as $idx => $machine) {
            $ctx = [$machine];
            $card_components = [];

            if (!empty($cfg['header_image'])) {
                $link = trim($this->evaluate((string) $cfg['header_image'], $ctx, $recipient));
                if ($link !== '') {
                    $card_components[] = [
                        'type'       => 'header',
                        'parameters' => [[
                            'type'  => 'image',
                            'image' => ['link' => $link],
                        ]],
                    ];
                }
            }

            if (!empty($cfg['body']) && is_array($cfg['body'])) {
                $is_named = $this->is_assoc($cfg['body']);
                $params = [];
                foreach ($cfg['body'] as $key => $expr) {
                    $param = [
                        'type' => 'text',
                        'text' => self::sanitize_param($this->evaluate((string) $expr, $ctx, $recipient)),
                    ];
                    if ($is_named) {
                        $param['parameter_name'] = (string) $key;
                    }
                    $params[] = $param;
                }
                $card_components[] = ['type' => 'body', 'parameters' => $params];
            }

            if (!empty($cfg['buttons']) && is_array($cfg['buttons'])) {
                foreach ($cfg['buttons'] as $btn_index => $btn) {
                    if (!is_array($btn) || empty($btn['sub_type'])) {
                        continue;
                    }
                    $sub = (string) $btn['sub_type'];
                    $value = self::sanitize_param($this->evaluate((string) ($btn['param'] ?? ''), $ctx, $recipient));
                    $param_type = $sub === 'quick_reply' ? 'payload' : 'text';
                    $param_key = $sub === 'quick_reply' ? 'payload' : 'text';
                    $card_components[] = [
                        'type'       => 'button',
                        'sub_type'   => $sub,
                        'index'      => (string) $btn_index,
                        'parameters' => [[
                            'type'      => $param_type,
                            $param_key  => $value,
                        ]],
                    ];
                }
            }

            $cards[] = [
                'card_index' => $idx,
                'components' => $card_components,
            ];
        }

        return [
            'type'  => 'carousel',
            'cards' => $cards,
        ];
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

        $expression = preg_replace_callback('/\{at:(\d+),([^}]+)\}/u', static function (array $m) use ($machines): string {
            $idx = (int) $m[1];
            if (!isset($machines[$idx])) {
                return '—';
            }
            $cols = array_map('trim', explode(',', $m[2]));
            $parts = [];
            foreach ($cols as $c) {
                $v = trim((string) ($machines[$idx][$c] ?? ''));
                if ($v !== '') {
                    $parts[] = $v;
                }
            }
            return $parts === [] ? '—' : implode(' ', $parts);
        }, $expression) ?? $expression;

        $expression = preg_replace_callback('/\{list:([^}]+)\}/u', static function (array $m) use ($machines): string {
            return self::join_columns($machines, $m[1], ' · ', '');
        }, $expression) ?? $expression;

        $expression = preg_replace_callback('/\{bullets:([^}]+)\}/u', static function (array $m) use ($machines): string {
            return self::join_columns($machines, $m[1], ' • ', '');
        }, $expression) ?? $expression;

        $expression = preg_replace_callback('/\{img:([^}]+)\}/u', static function (array $m) use ($machines): string {
            $col = trim($m[1]);
            $val = trim((string) ($machines[0][$col] ?? ''));
            if ($val === '') {
                return '';
            }
            if (preg_match('#^https?://#i', $val)) {
                return $val;
            }
            $pattern = trim((string) Settings::get('image_url_pattern', ''));
            if ($pattern === '' || strpos($pattern, '{value}') === false) {
                return $val;
            }
            return str_replace('{value}', rawurlencode($val), $pattern);
        }, $expression) ?? $expression;

        return $expression;
    }

    private static function sanitize_param(string $text): string
    {
        // WhatsApp rejects parameters with newlines, tabs, or 5+ consecutive spaces.
        $text = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $text);
        $text = preg_replace('/ {4,}/', '   ', $text) ?? $text;
        return trim($text);
    }

    private static function join_columns(array $machines, string $cols_csv, string $sep, string $prefix): string
    {
        $cols = array_map('trim', explode(',', $cols_csv));
        $items = [];
        foreach ($machines as $row) {
            $parts = [];
            foreach ($cols as $c) {
                $v = trim((string) ($row[$c] ?? ''));
                if ($v !== '') {
                    $parts[] = $v;
                }
            }
            if (!empty($parts)) {
                $items[] = $prefix . implode(' ', $parts);
            }
        }
        return implode($sep, $items);
    }

    private function is_assoc(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
