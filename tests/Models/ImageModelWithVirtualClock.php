<?php

declare(strict_types=1);

namespace WordPress\ApimartAiProvider\Tests\Models;

use WordPress\ApimartAiProvider\Models\ApimartImageGenerationModel;

/**
 * Advances a virtual clock when the model would otherwise wait between HTTP requests.
 */
class ImageModelWithVirtualClock extends ApimartImageGenerationModel
{
    /** @var float The current virtual time. */
    private float $clock = 1000.0;

    /** @var list<float> Recorded polling delays. */
    public array $delays = [];

    protected function currentTime(): float
    {
        return $this->clock;
    }

    protected function pause(float $seconds): void
    {
        $this->delays[] = $seconds;
        $this->clock += $seconds;
    }
}
