<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Option;

use Flytachi\Winter\SDK\S5w\Enum\ImageFormat;

/**
 * What to do with an image at upload: re-encode, set the quality, fit into a box.
 *
 * Processing happens once, on the server, and only the result is stored — the
 * original is not kept. EXIF rotation is applied, other metadata dropped.
 * Options on a file that is not an image are refused with a 422
 * ({@see \Flytachi\Winter\SDK\S5w\Exception\ValidationException}): pass them for images only.
 * On an image the server may still decide to skip — see
 * {@see \Flytachi\Winter\SDK\S5w\Entity\Processing::$reason}.
 *
 * Immutable; build it fluently:
 * ```
 * ImageOptions::webp()->fit(1600, 1600);       // WebP, default quality, inside 1600×1600
 * ImageOptions::avif(quality: 50);
 * ImageOptions::original()->fit(maxWidth: 800); // keep the format, only shrink
 * ```
 *
 * @link https://winterframe.net/packages/sdk-s5w/options-and-enums#imageoptions ImageOptions
 */
final readonly class ImageOptions
{
    /**
     * @param int|null $quality   1–100; null — the server default (82; PNG 60; AVIF 55)
     * @param int|null $maxWidth  16–10000; shrinks only, keeps the aspect ratio
     * @param int|null $maxHeight 16–10000; shrinks only, keeps the aspect ratio
     */
    public function __construct(
        public ImageFormat $format = ImageFormat::ORIGINAL,
        public ?int $quality = null,
        public ?int $maxWidth = null,
        public ?int $maxHeight = null,
    ) {
    }

    /**
     * Keep the source format; add {@see fit()} or {@see quality()} to do something.
     *
     * @link https://winterframe.net/packages/sdk-s5w/options-and-enums#imageoptions ImageOptions
     */
    public static function original(): self
    {
        return new self();
    }

    /** @link https://winterframe.net/packages/sdk-s5w/options-and-enums#imageoptions ImageOptions */
    public static function webp(?int $quality = null): self
    {
        return new self(ImageFormat::WEBP, $quality);
    }

    /** @link https://winterframe.net/packages/sdk-s5w/options-and-enums#imageoptions ImageOptions */
    public static function jpeg(?int $quality = null): self
    {
        return new self(ImageFormat::JPEG, $quality);
    }

    /** @link https://winterframe.net/packages/sdk-s5w/options-and-enums#imageoptions ImageOptions */
    public static function png(?int $quality = null): self
    {
        return new self(ImageFormat::PNG, $quality);
    }

    /** @link https://winterframe.net/packages/sdk-s5w/options-and-enums#imageoptions ImageOptions */
    public static function avif(?int $quality = null): self
    {
        return new self(ImageFormat::AVIF, $quality);
    }

    /**
     * Fit inside this box. Never enlarges; a 320×200 source under 160×160 becomes 160×100.
     *
     * @link https://winterframe.net/packages/sdk-s5w/options-and-enums#imageoptionsfit ImageOptions::fit()
     */
    public function fit(?int $maxWidth, ?int $maxHeight = null): self
    {
        return new self($this->format, $this->quality, $maxWidth, $maxHeight);
    }

    /** @link https://winterframe.net/packages/sdk-s5w/options-and-enums#imageoptionsquality ImageOptions::quality() */
    public function quality(int $quality): self
    {
        return new self($this->format, $quality, $this->maxWidth, $this->maxHeight);
    }

    /**
     * Whether these options ask for anything at all.
     *
     * @link https://winterframe.net/packages/sdk-s5w/options-and-enums#imageoptionsisempty ImageOptions::isEmpty()
     */
    public function isEmpty(): bool
    {
        return $this->format === ImageFormat::ORIGINAL
            && $this->quality === null
            && $this->maxWidth === null
            && $this->maxHeight === null;
    }

    /**
     * The request fields; what is not set is left out.
     *
     * @return array<string, string|int>
     * @internal
     */
    public function toFields(): array
    {
        return array_filter([
            'format' => $this->format === ImageFormat::ORIGINAL ? null : $this->format->value,
            'quality' => $this->quality,
            'maxWidth' => $this->maxWidth,
            'maxHeight' => $this->maxHeight,
        ], static fn($v) => $v !== null);
    }
}
