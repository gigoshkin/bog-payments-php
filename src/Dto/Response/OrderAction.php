<?php

declare(strict_types=1);

namespace Bog\Payments\Dto\Response;

use Bog\Payments\Enum\ActionType;

final readonly class OrderAction
{
    public function __construct(
        public ActionType           $type,
        public float                $amount,
        public string               $status,
        public \DateTimeImmutable   $timestamp,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type:      ActionType::from((string) ($data['type'] ?? '')),
            amount:    (float) ($data['amount'] ?? 0),
            status:    (string) ($data['status'] ?? ''),
            timestamp: new \DateTimeImmutable((string) ($data['timestamp'] ?? 'now')),
        );
    }
}
