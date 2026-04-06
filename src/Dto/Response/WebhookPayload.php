<?php

declare(strict_types=1);

namespace Bog\Payments\Dto\Response;

use Bog\Payments\Enum\OrderStatus;

final readonly class WebhookPayload
{
    public function __construct(
        public string             $event,
        public \DateTimeImmutable $requestTime,
        public OrderDetails       $order,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['event', 'body'] as $field) {
            if (!isset($data[$field])) {
                throw new \InvalidArgumentException(
                    sprintf('WebhookPayload: missing required field "%s".', $field),
                );
            }
        }

        return new self(
            event:       (string) $data['event'],
            requestTime: new \DateTimeImmutable((string) ($data['zoned_request_time'] ?? 'now')),
            order:       OrderDetails::fromArray($data['body']),
        );
    }
}
