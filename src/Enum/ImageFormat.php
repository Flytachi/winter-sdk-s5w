<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Enum;

/**
 * What an image is re-encoded into at upload. Mirrors the server's `ImageFormat`.
 *
 * @link https://winterframe.net/packages/sdk-s5w/options-and-enums#imageformat ImageFormat
 */
enum ImageFormat: string
{
    /** Keep the source format (resize / quality may still apply). */
    case ORIGINAL = 'ORIGINAL';
    case WEBP = 'WEBP';
    case JPEG = 'JPEG';
    case PNG = 'PNG';
    case AVIF = 'AVIF';

    /**
     * null for ORIGINAL — the source decides.
     *
     * @link https://winterframe.net/packages/sdk-s5w/options-and-enums#imageformat ImageFormat
     */
    public function mime(): ?string
    {
        return match ($this) {
            self::ORIGINAL => null,
            self::WEBP => 'image/webp',
            self::JPEG => 'image/jpeg',
            self::PNG => 'image/png',
            self::AVIF => 'image/avif',
        };
    }
}
