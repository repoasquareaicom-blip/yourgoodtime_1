<?php

namespace App\Services\Prediction;

class PlaylistBuilder
{
    /**
     * @var array<int, mixed>
     */
    private array $playlist = [];

    public function addVideo(
        mixed $video,
        ?string $ruleCode = null,
        ?string $ruleName = null,
        array $identifiedValues = [],
        array $matchedValues = []
    ): self
    {
        if (is_array($video) && array_key_exists('video_id', $video) && $ruleCode === null) {
            $this->playlist[] = array_merge([
                'sequence' => count($this->playlist) + 1,
            ], $video);

            return $this;
        }

        $item = [
            'sequence' => count($this->playlist) + 1,
            'video_id' => (string) $video,
        ];

        if ($ruleCode !== null || $ruleName !== null || $identifiedValues !== [] || $matchedValues !== []) {
            $item['source'] = [
                'rule_code' => $ruleCode,
                'rule_name' => $ruleName,
                'identified_values' => $identifiedValues,
                'matched_values' => $matchedValues,
            ];
        }

        $this->playlist[] = $item;

        return $this;
    }

    public function removeVideo(mixed $video): self
    {
        $this->playlist = array_values(array_filter(
            $this->playlist,
            fn (mixed $playlistVideo): bool => $playlistVideo !== $video
        ));

        return $this;
    }

    public function getPlaylist(): array
    {
        return $this->playlist;
    }

    public function clear(): self
    {
        $this->playlist = [];

        return $this;
    }
}
