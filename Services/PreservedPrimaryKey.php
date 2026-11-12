<?php

namespace AppLocalPlugins\EpgEnricher\Services;

final class PreservedPrimaryKey
{
    public static function slot(int $epgId, string $channelId, int $start, int $stop): string
    {
        return self::digest([$epgId, $channelId, $start, $stop]);
    }

    public static function identity(array $programme): string
    {
        return self::digest([
            $programme['title'] ?? null,
            $programme['subtitle'] ?? null,
            $programme['episode_num'] ?? null,
            $programme['episode_nums'] ?? null,
            $programme['production_year'] ?? null,
        ]);
    }

    private static function digest(array $values): string
    {
        return hash('sha256', json_encode(
            $values,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }
}
