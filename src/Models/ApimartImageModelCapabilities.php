<?php

declare(strict_types=1);

namespace WordPress\ApimartAiProvider\Models;

/**
 * Documented capabilities of the asynchronous GPT Image model families.
 *
 * Kept in one place so the model picker and request validation advertise the same contract.
 */
final class ApimartImageModelCapabilities
{
    /**
     * @param string $modelId The exact identifier returned by the model catalog.
     * @return array{
     *     aspectRatios: list<string>, candidateCounts: list<int>, mimeTypes: list<string>,
     *     maxReferenceImages: int, outputFormat: bool, mask: bool, resolutions: list<string>,
     *     qualities: list<string>, versions: list<string>, automaticSize: bool, pixelDimensions: bool
     * }|null The supported image contract, or null for an unimplemented model.
     */
    public static function forModel(string $modelId): ?array
    {
        $legacy = in_array($modelId, ['gpt-image-1-official', 'gpt-image-1.5-official'], true);
        $two = in_array($modelId, ['gpt-image-2', 'gpt-image-2-ext'], true);
        $official = $modelId === 'gpt-image-2-official';
        $twoFive = in_array($modelId, ['gpt-image-2.5-flare', 'gpt-image-2.5-sunburst'], true);
        $ext = $modelId === 'gpt-image-2.5-ext';
        if (!$legacy && !$two && !$official && !$twoFive && !$ext) {
            return null;
        }

        $ratios = $legacy ? ['1:1', '3:2', '2:3'] : [
            '1:1', '3:2', '2:3', '4:3', '3:4', '5:4', '4:5', '16:9', '9:16', '21:9',
        ];
        if ($two || $official || $twoFive) {
            $ratios = array_merge($ratios, ['2:1', '1:2', '3:1', '1:3', '9:21']);
        }
        $formats = $two || $ext ? ['image/png'] : ['image/png', 'image/jpeg'];
        if ($official || $twoFive) {
            $formats[] = 'image/webp';
        }

        return [
            'aspectRatios' => $ratios,
            // The GPT Image 2 parameter reference explicitly limits n to 1.
            'candidateCounts' => $two ? [1] : [1, 2, 3, 4],
            'mimeTypes' => $formats,
            'maxReferenceImages' => $legacy || $two ? 15 : 16,
            'outputFormat' => !$two && !$ext,
            'mask' => !$two && !$ext,
            'resolutions' => $legacy ? [] : ($ext ? ['1K', '2K', '4K'] : ['1k', '2k', '4k']),
            'qualities' => $official ? ['auto', 'low', 'medium', 'high'] :
                ($twoFive ? ['auto', 'low', 'medium', 'high', 'xhigh', 'max'] : []),
            'versions' => $ext ? ['flare', 'sunburst'] : [],
            'automaticSize' => !$legacy,
            'pixelDimensions' => $twoFive,
        ];
    }
}
