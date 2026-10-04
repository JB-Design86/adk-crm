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
    public static function targetGroupOptions(bool $withInactive = false): array
    {
        return collect(config('adk.target_groups'))
            ->filter(fn (array $group) => $withInactive || ($group['active'] ?? true))
            ->map(fn (array $group) => $group['label'])
            ->all();
    }

    /** Zielgruppe wird angeboten (config adk.target_groups.*.active). */
    public static function isActiveTargetGroup(?string $key): bool
    {
        return $key !== null && (bool) config("adk.target_groups.{$key}.active", array_key_exists($key, config('adk.target_groups')));
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

    /**
     * Dokumentarten. Ohne Recht health.view fehlt die Art „Gesundheitsangaben“.
     *
     * @return array<string, string>
     */
    public static function documentCategoryOptions(bool $withHealth = false): array
    {
        return collect(config('adk.documents.categories'))
            ->reject(fn (array $category) => ! $withHealth && ($category['health'] ?? false))
            ->map(fn (array $category) => $category['label'])
            ->all();
    }

    public static function documentCategoryLabel(?string $key): ?string
    {
        return $key ? (config("adk.documents.categories.{$key}.label") ?? $key) : null;
    }

    public static function isHealthCategory(?string $key): bool
    {
        return (bool) config("adk.documents.categories.{$key}.health", false);
    }

    /** @return array<string, string> */
    public static function participantStateOptions(): array
    {
        return array_map(fn (array $state) => $state['label'], config('adk.participant_states'));
    }

    public static function participantStateLabel(?string $key): ?string
    {
        return $key ? (config("adk.participant_states.{$key}.label") ?? $key) : null;
    }

    public static function participantStateColor(?string $key): string
    {
        return config("adk.participant_states.{$key}.color", 'gray');
    }

    public static function placementLabel(?string $key): ?string
    {
        return $key ? (config("adk.placement_statuses.{$key}") ?? $key) : null;
    }
}
