<?php

namespace App\Support;

/**
 * Zugriff auf die fachliche Konfiguration in config/adk.php.
 */
class Adk
{
    /** @return array<string, array<string, mixed>> */
    public static function statuses(): array
    {
        return config('adk.statuses');
    }

    /** @return array<string, mixed> */
    public static function status(string $key): array
    {
        return config("adk.statuses.{$key}") ?? throw new \InvalidArgumentException("Unbekannter Status: {$key}");
    }

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        return array_map(fn (array $status) => $status['label'], self::statuses());
    }

    public static function statusLabel(?string $key): ?string
    {
        return $key === null ? null : (config("adk.statuses.{$key}.label") ?? $key);
    }

    public static function statusColor(?string $key): string
    {
        return config("adk.statuses.{$key}.color") ?? 'gray';
    }

    /** Status, der zu einer Taste gehört. */
    public static function statusForKey(string $key): ?string
    {
        foreach (self::statuses() as $status => $definition) {
            if (($definition['key'] ?? null) === $key) {
                return $status;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function closingStatuses(): array
    {
        return array_keys(array_filter(self::statuses(), fn (array $status) => $status['closes']));
    }

    /** @return list<string> */
    public static function reachedStatuses(): array
    {
        return array_keys(array_filter(self::statuses(), fn (array $status) => $status['reached']));
    }

    /** @return array<string, string> */
    public static function targetGroupOptions(): array
    {
        return array_map(fn (array $group) => $group['label'], config('adk.target_groups'));
    }

    public static function targetGroupLabel(?string $key): ?string
    {
        return $key === null ? null : (config("adk.target_groups.{$key}.label") ?? $key);
    }

    /** @return array<string, string> */
    public static function channelOptions(): array
    {
        return array_map(fn (array $channel) => $channel['label'], config('adk.channels'));
    }

    public static function channelLabel(?string $key): ?string
    {
        return $key === null ? null : (config("adk.channels.{$key}.label") ?? $key);
    }

    /** @return list<string> */
    public static function inboundChannels(): array
    {
        return array_keys(array_filter(config('adk.channels'), fn (array $channel) => $channel['inbound']));
    }

    public static function isInbound(?string $channel): bool
    {
        return (bool) config("adk.channels.{$channel}.inbound", false);
    }

    /** @return array<string, string> */
    public static function roleOptions(): array
    {
        return array_map(fn (array $role) => $role['label'], config('adk.roles'));
    }

    /** @return array<string, string> */
    public static function priorityOptions(): array
    {
        $priorities = config('adk.priorities');

        return array_combine($priorities, $priorities);
    }

    /** @return array<string, string> */
    public static function industryOptions(): array
    {
        $industries = array_keys(config('adk.industries'));

        return array_combine($industries, $industries);
    }
}
