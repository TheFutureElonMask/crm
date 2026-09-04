<?php

namespace App\Services;

use InvalidArgumentException;

class GreenApiGateways
{
    /** @return array<string, array{key: string, host: string, id: string, token: string, manager_email: string}> */
    public function all(): array
    {
        $configured = config('services.green_api.gateways', []);
        $gateways = [];

        foreach (is_array($configured) ? $configured : [] as $key => $gateway) {
            $id = trim((string) ($gateway['id'] ?? ''));
            $token = trim((string) ($gateway['token'] ?? ''));

            if ($id === '' || $token === '') {
                continue;
            }

            $gateways[(string) $key] = [
                'key' => (string) $key,
                'host' => rtrim((string) ($gateway['host'] ?? config('services.green_api.host')), '/'),
                'id' => $id,
                'token' => $token,
                'manager_email' => strtolower(trim((string) ($gateway['manager_email'] ?? ''))),
            ];
        }

        return $gateways;
    }

    /** @return array{key: string, host: string, id: string, token: string, manager_email: string}|null */
    public function findByInstance(?string $instanceId): ?array
    {
        $instanceId = trim((string) $instanceId);

        if ($instanceId !== '') {
            foreach ($this->all() as $gateway) {
                if (hash_equals($gateway['id'], $instanceId)) {
                    return $gateway;
                }
            }

            return null;
        }

        $gateways = $this->all();

        return count($gateways) === 1 ? reset($gateways) : null;
    }

    /** @return array{key: string, host: string, id: string, token: string, manager_email: string}|null */
    public function findByManagerEmail(?string $email): ?array
    {
        $email = strtolower(trim((string) $email));
        if ($email === '') {
            return null;
        }

        foreach ($this->all() as $gateway) {
            if ($gateway['manager_email'] !== '' && hash_equals($gateway['manager_email'], $email)) {
                return $gateway;
            }
        }

        return null;
    }

    /** @return array{key: string, host: string, id: string, token: string, manager_email: string} */
    public function requireByInstance(?string $instanceId): array
    {
        $gateway = $this->findByInstance($instanceId);

        if (! $gateway) {
            throw new InvalidArgumentException('Green API instance is not configured: '.($instanceId ?: '[missing]'));
        }

        return $gateway;
    }
}
