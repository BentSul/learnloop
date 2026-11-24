<?php

namespace App\Services\Adaptive\Lecturer;

use App\Models\Topic;
use App\Models\TopicResource;
use App\Models\User;
use App\Services\Adaptive\CourseAccess;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Suggests YouTube videos for a topic (YouTube Data API v3, search.list). Suggestions are saved
 * as "suggested" resources that students cannot see until a lecturer approves them. Searches
 * are limited to one per topic every 24 hours because the API's daily search allowance is small.
 */
class YouTubeSuggestionService
{
    private const ENDPOINT = 'https://www.googleapis.com/youtube/v3/search';

    private const PER_SEARCH = 5;

    public function __construct(private readonly CourseAccess $access)
    {
    }

    public function enabled(): bool
    {
        return filled(config('adaptive.learning_sources.youtube_api_key'));
    }

    /** @return list<array<string, mixed>> the topic's resources after the search, suggestions included */
    public function suggest(User $lecturer, Topic $topic): array
    {
        if (! $this->access->isLecturer($lecturer, $topic->course)) {
            throw LecturerException::notFound();
        }
        if (! $this->enabled()) {
            throw LecturerException::conflict('Video suggestions are not set up yet: add a YouTube API key (YOUTUBE_API_KEY).');
        }
        if (! Cache::add("youtube-suggest:{$topic->id}", true, now()->addDay())) {
            throw LecturerException::conflict('Videos were already suggested for this topic in the last 24 hours. Review those first, or add links yourself.');
        }

        try {
            $response = Http::acceptJson()->timeout((int) config('adaptive.learning_sources.timeout_seconds', 15))
                ->get(self::ENDPOINT, [
                    'part' => 'snippet',
                    'type' => 'video',
                    'maxResults' => self::PER_SEARCH,
                    'safeSearch' => 'strict',
                    'videoEmbeddable' => 'true',
                    'relevanceLanguage' => 'en',
                    'q' => trim($topic->name.' '.$topic->course->name.' tutorial'),
                    'key' => config('adaptive.learning_sources.youtube_api_key'),
                ]);
        } catch (ConnectionException) {
            Cache::forget("youtube-suggest:{$topic->id}");
            throw LecturerException::unavailable('YouTube could not be reached. Try again later.');
        }

        if (! $response->successful()) {
            Cache::forget("youtube-suggest:{$topic->id}");
            throw LecturerException::unavailable(in_array($response->status(), [400, 403], true)
                ? 'YouTube refused the request: the daily limit may be used up or the API key is not valid.'
                : "YouTube returned an error ({$response->status()}). Try again later.");
        }

        $next = (int) $topic->resources()->max('position');
        foreach ((array) $response->json('items', []) as $item) {
            $videoId = (string) ($item['id']['videoId'] ?? '');
            if (! preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId)) {
                continue;
            }
            $title = html_entity_decode((string) ($item['snippet']['title'] ?? 'YouTube video'), ENT_QUOTES | ENT_HTML5);
            $channel = html_entity_decode((string) ($item['snippet']['channelTitle'] ?? ''), ENT_QUOTES | ENT_HTML5);

            TopicResource::firstOrCreate(
                ['topic_id' => $topic->id, 'source' => 'youtube', 'external_id' => $videoId],
                [
                    'kind' => 'video',
                    'title' => mb_substr($title, 0, 200),
                    'url' => "https://www.youtube.com/watch?v={$videoId}",
                    'note' => $channel !== '' ? "Channel: {$channel}" : null,
                    'status' => 'suggested',
                    'position' => ++$next,
                    'added_by' => $lecturer->id,
                ],
            );
        }

        return $topic->resources()->get()->map(fn (TopicResource $r) => $r->toLecturerArray())->all();
    }
}
