<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Traffic;

/**
 * @internal
 */
final readonly class Window
{
    public function __construct(
        public int $from,
        public int $to,
    ) {
    }

    public static function ending(int $now, Range $range): self
    {
        $to = (intdiv($now, 60) + 1) * 60;

        return new self($to - $range->seconds(), $to);
    }

    public function previous(int $length = 0): self
    {
        $length = $length > 0 ? $length : $this->to - $this->from;

        return new self($this->from - $length, $this->from);
    }

    /**
     * @return list<self>
     */
    public function split(int $parts): array
    {
        $size = intdiv($this->to - $this->from, max(1, $parts));
        $windows = [];
        for ($index = 0; $index < $parts; ++$index) {
            $from = $this->from + $index * $size;
            $windows[] = new self($from, $index === $parts - 1 ? $this->to : $from + $size);
        }

        return $windows;
    }
}
