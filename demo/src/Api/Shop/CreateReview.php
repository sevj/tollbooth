<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Api\Shop;

use Tollbooth\Auth\Consumer;
use Tollbooth\Core\Attribute\Body;
use Tollbooth\Core\Attribute\Endpoint;
use Tollbooth\Demo\Model\CreateReviewInput;
use Tollbooth\Demo\Model\ReviewView;
use Tollbooth\Demo\Store;

#[Endpoint('POST', '/products/{id}/reviews', status: 201)]
final readonly class CreateReview
{
    public function __construct(private Store $store)
    {
    }

    public function __invoke(string $id, #[Body] CreateReviewInput $input, Consumer $consumer): ReviewView
    {
        return $this->store->addReview($id, $input->rating, $input->comment, $consumer->name);
    }
}
