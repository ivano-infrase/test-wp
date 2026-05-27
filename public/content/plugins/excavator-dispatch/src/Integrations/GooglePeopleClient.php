<?php

declare(strict_types=1);

namespace InfraSe\ExcavatorDispatch\Integrations;

use InfraSe\ExcavatorDispatch\Support\Cache;
use InfraSe\ExcavatorDispatch\Support\Settings;
use RuntimeException;

final class GooglePeopleClient
{
    private const TOKEN_TRANSIENT = 'google_token';
    private const SCOPE = 'https://www.googleapis.com/auth/contacts.readonly';
    private const API_BASE = 'https://people.googleapis.com/v1';

    public function fetch_contacts(): array
    {
        $token = $this->access_token();
        $contacts = [];
        $pageToken = null;
        do {
            $url = self::API_BASE . '/people/me/connections?personFields=names,emailAddresses,phoneNumbers,memberships,organizations&pageSize=1000';
            if ($pageToken) {
                $url .= '&pageToken=' . rawurlencode($pageToken);
            }
            $data = $this->google_get($token, $url);
            foreach ((array) ($data['connections'] ?? []) as $person) {
                $contacts[] = $this->normalize_person($person);
            }
            $pageToken = $data['nextPageToken'] ?? null;
        } while ($pageToken);

        $contacts = array_merge($contacts, $this->fetch_group_members($token));
        return $this->deduplicate($contacts);
    }

    private function fetch_group_members(string $token): array
    {
        $groupIds = (array) Settings::get('google_group_ids', []);
        if (empty($groupIds)) {
            return [];
        }
        $out = [];
        foreach ($groupIds as $gid) {
            $gid = trim((string) $gid);
            if ($gid === '') {
                continue;
            }
            $group = $this->google_get($token, self::API_BASE . '/' . rawurlencode($gid) . '?maxMembers=1000');
            $memberResourceNames = (array) ($group['memberResourceNames'] ?? []);
            foreach (array_chunk($memberResourceNames, 50) as $chunk) {
                $params = '';
                foreach ($chunk as $rn) {
                    $params .= '&resourceNames=' . rawurlencode($rn);
                }
                $data = $this->google_get($token, self::API_BASE . '/people:batchGet?personFields=names,emailAddresses,phoneNumbers,memberships,organizations' . $params);
                foreach ((array) ($data['responses'] ?? []) as $entry) {
                    if (!empty($entry['person'])) {
                        $out[] = $this->normalize_person($entry['person']);
                    }
                }
            }
        }
        return $out;
    }

    private function deduplicate(array $contacts): array
    {
        $seen = [];
        $out = [];
        foreach ($contacts as $c) {
            $key = $c['resource_name'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $c;
        }
        return $out;
    }

    private function normalize_person(array $p): array
    {
        $name = $p['names'][0]['displayName'] ?? '';
        $email = $p['emailAddresses'][0]['value'] ?? '';
        $org = $p['organizations'][0]['name'] ?? '';

        $phones = (array) ($p['phoneNumbers'] ?? []);
        usort($phones, static function (array $a, array $b): int {
            $ra = ($a['type'] ?? '') === 'mobile' ? 0 : 1;
            $rb = ($b['type'] ?? '') === 'mobile' ? 0 : 1;
            return $ra <=> $rb;
        });
        $phone = '';
        foreach ($phones as $ph) {
            $candidate = (string) ($ph['canonicalForm'] ?? $ph['value'] ?? '');
            if ($candidate !== '') {
                $phone = $candidate;
                break;
            }
        }

        $groups = [];
        foreach ((array) ($p['memberships'] ?? []) as $m) {
            $gid = $m['contactGroupMembership']['contactGroupResourceName'] ?? null;
            if ($gid) {
                $groups[] = (string) $gid;
            }
        }

        return [
            'resource_name' => (string) ($p['resourceName'] ?? ''),
            'name'          => (string) $name,
            'email'         => (string) $email,
            'organization'  => (string) $org,
            'phone'         => $this->to_e164($phone),
            'phone_raw'     => $phone,
            'groups'        => $groups,
        ];
    }

    private function to_e164(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }
        if (strpos($phone, '+') === 0) {
            return '+' . preg_replace('/\D+/', '', $phone);
        }
        $digits = preg_replace('/\D+/', '', $phone);
        $default_cc = (string) Settings::get('default_country_code', '');
        if ($default_cc !== '' && $digits !== '') {
            return '+' . ltrim($default_cc, '+') . $digits;
        }
        return $digits === '' ? '' : '+' . $digits;
    }

    private function access_token(): string
    {
        return Cache::remember(self::TOKEN_TRANSIENT, 3000, function () {
            $raw = (string) Settings::get('google_service_account_json', '');
            if ($raw === '') {
                throw new RuntimeException('Google: service account JSON non configurato.');
            }
            $sa = json_decode($raw, true);
            if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
                throw new RuntimeException('Google: JSON service account non valido.');
            }
            $subject = (string) Settings::get('google_impersonate_email', '');
            if ($subject === '') {
                throw new RuntimeException('Google: impostare l\'email dell\'utente impersonato (domain-wide delegation).');
            }

            $now = time();
            $header = ['alg' => 'RS256', 'typ' => 'JWT'];
            $claims = [
                'iss'   => $sa['client_email'],
                'sub'   => $subject,
                'scope' => self::SCOPE,
                'aud'   => 'https://oauth2.googleapis.com/token',
                'exp'   => $now + 3600,
                'iat'   => $now,
            ];
            $segments = [
                self::b64url(json_encode($header)),
                self::b64url(json_encode($claims)),
            ];
            $signing_input = implode('.', $segments);
            $signature = '';
            if (!openssl_sign($signing_input, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Google: firma JWT fallita.');
            }
            $jwt = $signing_input . '.' . self::b64url($signature);

            $resp = wp_remote_post('https://oauth2.googleapis.com/token', [
                'timeout' => 20,
                'body' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $jwt,
                ],
            ]);
            if (is_wp_error($resp)) {
                throw new RuntimeException('Google token: ' . $resp->get_error_message());
            }
            $data = json_decode((string) wp_remote_retrieve_body($resp), true);
            if (!is_array($data) || empty($data['access_token'])) {
                throw new RuntimeException('Google token: risposta non valida.');
            }
            return (string) $data['access_token'];
        });
    }

    private function google_get(string $token, string $url): array
    {
        $resp = wp_remote_get($url, [
            'timeout' => 20,
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
        ]);
        if (is_wp_error($resp)) {
            throw new RuntimeException('People GET: ' . $resp->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = (string) wp_remote_retrieve_body($resp);
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("People GET {$code}: {$body}");
        }
        $data = json_decode($body, true);
        return is_array($data) ? $data : [];
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
