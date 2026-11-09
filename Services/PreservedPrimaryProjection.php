<?php

namespace AppLocalPlugins\EpgEnricher\Services;

use App\Http\Controllers\LogoProxyController;
use App\Http\Controllers\PlaylistGenerateController;
use App\Models\AedProfile;
use App\Models\CustomPlaylist;
use App\Models\Epg;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Services\EpgCacheService;
use App\Support\EpgProgrammeArtwork;
use Carbon\CarbonImmutable;

require_once __DIR__.'/PreservedPrimaryRepository.php';

class PreservedPrimaryProjection
{
    public function entries(array $payload, object $context): iterable
    {
        $scope = $context->playlist ?? null;
        $type = $scope->type ?? null;
        if (! in_array($type, [Playlist::class, CustomPlaylist::class, MergedPlaylist::class, PlaylistAlias::class], true)) {
            throw new \InvalidArgumentException('Unsupported playlist scope.');
        }
        $playlist = $type::query()->whereKey($scope->id)->where('user_id', $scope->user_id)->first();
        if (! $playlist || ($playlist instanceof Playlist && $playlist->is_network_playlist)
            || ($payload['format'] === 'xmltv' && ! $playlist->xmltv_enabled)) {
            return;
        }
        $settings = $context->settings;
        $repository = new PreservedPrimaryRepository;
        if (! $repository->supported() || PreservedPrimaryRepository::overwritten($settings)) {
            return;
        }
        // The same output query owns alias filters, custom pivots and merged membership.
        $channels = PlaylistGenerateController::getChannelQuery($playlist, false)
            ->whereIn('channels.id', $payload['stream_ids'])->get();
        $overrides = AedProfile::query()->where('user_id', $playlist->user_id)->where('override', true)->pluck('id')->all();
        $cache = new EpgCacheService;
        foreach ($channels as $channel) {
            if ($channel->network_id || in_array($channel->aed_profile_id ?? $channel->group_aed_profile_id, $overrides, true)) {
                continue;
            }
            $raw = $channel->epgChannel;
            $epg = $raw ? Epg::find($raw->epg_id) : null;
            if (! $epg || ! $epg->is_cached) {
                continue;
            }
            $shift = (int) ($channel->tvg_shift ?? 0);
            $from = CarbonImmutable::createFromTimestampUTC($payload['start_timestamp'])->subHours($shift);
            $to = CarbonImmutable::createFromTimestampUTC($payload['stop_timestamp'])->subHours($shift);
            $programmes = $cache->getCachedProgrammesRange($epg, $from->format('Y-m-d'), $to->format('Y-m-d'), [$raw->channel_id]);
            $seen = [];
            foreach ($programmes[$raw->channel_id] ?? [] as $programme) {
                $slot = PreservedPrimaryRepository::slot((int) $epg->id, $programme);
                if ($slot !== null) {
                    $seen[$slot['key']] = ($seen[$slot['key']] ?? 0) + 1;
                }
            }
            foreach ($programmes[$raw->channel_id] ?? [] as $programme) {
                $slot = PreservedPrimaryRepository::slot((int) $epg->id, $programme);
                if ($slot === null || $seen[$slot['key']] !== 1) {
                    continue;
                }
                $primary = $repository->validated((int) $epg->id, $programme, $settings);
                if ($primary === null) {
                    continue;
                }
                $wire = EpgCacheService::shiftProgrammes([$programme], $shift)[0];
                $start = CarbonImmutable::parse($wire['start'])->timestamp;
                $stop = CarbonImmutable::parse($wire['stop'])->timestamp;
                if ($start < $payload['start_timestamp'] || $start >= $payload['stop_timestamp'] || $stop <= $start) {
                    continue;
                }
                yield $this->entry((int) $channel->id, $start, $stop, $programme, $primary, $epg, $playlist, $payload['format']);
            }
        }
    }

    private function entry(int $streamId, int $start, int $stop, array $programme, string $primary, Epg $epg, object $playlist, string $format): array
    {
        $images = $programme['images'] ?? [];
        $url = fn (?string $value): ?string => $value !== null && PreservedPrimaryRepository::validUrl($value)
            ? $this->wireUrl($value, $epg, $playlist, $format) : null;
        $poster = EpgProgrammeArtwork::poster($images, $programme['icon'] ?? null);
        $backdrop = EpgProgrammeArtwork::backdrop($images);
        $still = EpgProgrammeArtwork::still($images);
        $posterGeometry = $poster !== null ? (EpgProgrammeArtwork::urlGeometry($images, trim($poster['url'])) ?? [0, 0]) : [0, 0];
        $stillGeometry = $still !== null ? (EpgProgrammeArtwork::urlGeometry($images, trim($still['url'])) ?? [0, 0]) : [0, 0];
        $geometry = EpgProgrammeArtwork::urlGeometry($images, $primary) ?? [0, 0];

        return [
            'stream_id' => $streamId, 'start_timestamp' => $start, 'stop_timestamp' => $stop,
            'title' => $programme['title'] ?? '', 'subtitle' => $format === 'json' ? null : (($programme['subtitle'] ?? '') ?: null),
            'guide' => [
                'primary_icon_url' => $format === 'json' ? null : $url($programme['icon'] ?? null),
                'poster_url' => $url($poster === null ? null : trim($poster['url'])),
                'poster_width' => $posterGeometry[0], 'poster_height' => $posterGeometry[1],
                'backdrop_url' => $url($backdrop === null ? null : trim($backdrop['url'])),
                'still_url' => $url($still === null ? null : trim($still['url'])),
                'still_width' => $stillGeometry[0], 'still_height' => $stillGeometry[1],
            ],
            'primary' => ['url' => $url($primary), 'width' => $geometry[0], 'height' => $geometry[1]],
        ];
    }

    private function wireUrl(string $url, Epg $epg, object $playlist, string $format): string
    {
        if (! $playlist->enable_logo_proxy) {
            return $url;
        }
        if ($format === 'json') {
            return str_starts_with($url, url('/')) ? $url : LogoProxyController::generateProxyUrl($url);
        }
        // Match the XMLTV first-party Schedules Direct exception; never decode or rewrite URLs.
        if ($epg->isSchedulesDirect()) {
            $parts = parse_url($url);
            if (is_array($parts) && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['fragment'])
                && (! isset($parts['query']) || preg_match('/^v=\d+$/D', $parts['query']) === 1)
                && preg_match('#/schedules-direct/'.preg_quote($epg->uuid, '#').'/image/([A-Za-z0-9._-]+)$#D', $parts['path'] ?? '', $m) === 1
                && ! in_array($m[1], ['.', '..'], true)) {
                return $url;
            }
        }

        return LogoProxyController::generateProxyUrl($url);
    }
}
