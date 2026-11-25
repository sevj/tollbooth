<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Model;

final readonly class ReviewView
{
    public function __construct(
        public string $id,
        public string $productId,
        public Rating $rating,
        public string $comment,
        public string $author,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
