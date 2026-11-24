<?php

namespace App\Services\Adaptive\Lecturer;

use App\Models\Topic;
use App\Models\TopicResource;
use App\Models\User;
use App\Services\Adaptive\CourseAccess;

/**
 * Lecturers curate study resources per topic. Only http(s) links are accepted; students see
 * published resources only, so suggestions can be reviewed first.
 */
class TopicResourceService
{
    public function __construct(private readonly CourseAccess $access)
    {
    }

    /** @return list<array<string, mixed>> all resources, including suggestions waiting for review */
    public function forLecturer(User $lecturer, Topic $topic): array
    {
        $this->assertLecturer($lecturer, $topic);

        return $topic->resources()->get()->map(fn (TopicResource $r) => $r->toLecturerArray())->all();
    }

    /** @param array<string, mixed> $data {kind, title, url, note?} */
    public function create(User $lecturer, Topic $topic, array $data): TopicResource
    {
        $this->assertLecturer($lecturer, $topic);
        $clean = $this->validate($data, true);

        return TopicResource::create($clean + [
            'topic_id' => $topic->id,
            'position' => (int) $topic->resources()->max('position') + 1,
            'added_by' => $lecturer->id,
        ]);
    }

    /** @param array<string, mixed> $data any of {kind, title, url, note, position, status: published} */
    public function update(User $lecturer, TopicResource $resource, array $data): TopicResource
    {
        $this->assertLecturer($lecturer, $resource->topic);
        $clean = $this->validate($data, false);

        if (array_key_exists('position', $data)) {
            $clean['position'] = max(0, (int) $data['position']);
        }
        if (array_key_exists('status', $data)) {
            if ($data['status'] !== 'published') {
                throw LecturerException::invalid('The given data was invalid.', ['status can only be set to published (approve).']);
            }
            $clean['status'] = 'published';
        }

        $resource->update($clean);

        return $resource->refresh();
    }

    public function delete(User $lecturer, TopicResource $resource): void
    {
        $this->assertLecturer($lecturer, $resource->topic);
        $resource->delete();
    }

    /** @return array<string, mixed> */
    private function validate(array $data, bool $creating): array
    {
        $errors = [];
        $clean = [];

        if ($creating || array_key_exists('kind', $data)) {
            $kind = strtolower(trim((string) ($data['kind'] ?? '')));
            in_array($kind, TopicResource::KINDS, true)
                ? $clean['kind'] = $kind
                : $errors[] = 'kind must be one of: '.implode(', ', TopicResource::KINDS).'.';
        }
        if ($creating || array_key_exists('title', $data)) {
            $title = trim((string) ($data['title'] ?? ''));
            ($title === '' || mb_strlen($title) > 200)
                ? $errors[] = 'title is required (at most 200 characters).'
                : $clean['title'] = $title;
        }
        if ($creating || array_key_exists('url', $data)) {
            $url = trim((string) ($data['url'] ?? ''));
            self::isSafeUrl($url)
                ? $clean['url'] = $url
                : $errors[] = 'url must be a full http:// or https:// web address.';
        }
        if (array_key_exists('note', $data)) {
            $note = $data['note'] === null ? null : trim((string) $data['note']);
            ($note !== null && mb_strlen($note) > 1000)
                ? $errors[] = 'note must be at most 1000 characters.'
                : $clean['note'] = $note === '' ? null : $note;
        }

        if ($errors !== []) {
            throw LecturerException::invalid('The given data was invalid.', $errors);
        }

        return $clean;
    }

    /** Only absolute http(s) URLs with a host: no javascript:, data: or relative links reach students. */
    public static function isSafeUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/\s/', $url)) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts)
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ! empty($parts['host'])
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    private function assertLecturer(User $lecturer, Topic $topic): void
    {
        if (! $this->access->isLecturer($lecturer, $topic->course)) {
            throw LecturerException::notFound();
        }
    }
}
