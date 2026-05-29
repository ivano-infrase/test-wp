<?php

declare(strict_types=1);

namespace InfraSe\ExcavatorDispatch\Services;

use InfraSe\ExcavatorDispatch\Support\Settings;

final class MachineFilter
{
    public function apply(array $rows): array
    {
        $rules = (array) Settings::get('machine_filters', []);
        if (empty($rules)) {
            return array_values($rows);
        }
        $out = [];
        foreach ($rows as $row) {
            if ($this->matches($row, $rules)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * @param array<int, array{column: string, op: string, value: string}> $rules
     */
    private function matches(array $row, array $rules): bool
    {
        foreach ($rules as $rule) {
            $col = (string) ($rule['column'] ?? '');
            $op  = (string) ($rule['op'] ?? 'eq');
            $val = (string) ($rule['value'] ?? '');
            if ($col === '') {
                continue;
            }
            $cell = (string) ($row[$col] ?? '');
            if (!$this->compare($cell, $op, $val)) {
                return false;
            }
        }
        return true;
    }

    private function compare(string $cell, string $op, string $value): bool
    {
        $cellLc = mb_strtolower($cell);
        $valLc  = mb_strtolower($value);
        switch ($op) {
            case 'eq':       return $cellLc === $valLc;
            case 'neq':      return $cellLc !== $valLc;
            case 'contains': return $valLc === '' || strpos($cellLc, $valLc) !== false;
            case 'starts':   return $valLc === '' || strpos($cellLc, $valLc) === 0;
            case 'not_empty':return $cell !== '';
            case 'empty':    return $cell === '';
            default:         return false;
        }
    }
}
