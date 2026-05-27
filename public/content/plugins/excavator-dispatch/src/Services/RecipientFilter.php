<?php

declare(strict_types=1);

namespace InfraSe\ExcavatorDispatch\Services;

use InfraSe\ExcavatorDispatch\Support\Settings;

final class RecipientFilter
{
    public function apply(array $contacts): array
    {
        $require_phone = (bool) Settings::get('recipients_require_phone', true);
        $name_regex    = (string) Settings::get('recipients_name_regex', '');
        $only_groups   = (array) Settings::get('google_group_ids', []);
        $only_groups   = array_filter(array_map('strval', $only_groups));

        $out = [];
        foreach ($contacts as $c) {
            if ($require_phone && empty($c['phone'])) {
                continue;
            }
            if ($name_regex !== '' && @preg_match('/' . $name_regex . '/u', (string) ($c['name'] ?? '')) !== 1) {
                continue;
            }
            if (!empty($only_groups)) {
                $intersection = array_intersect($only_groups, (array) ($c['groups'] ?? []));
                if (empty($intersection)) {
                    continue;
                }
            }
            $out[] = $c;
        }
        return $out;
    }
}
