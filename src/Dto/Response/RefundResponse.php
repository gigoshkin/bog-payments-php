<?php

declare(strict_types=1);

namespace Bog\Payments\Dto\Response;

final readonly class RefundResponse
{
    public function __construct(
        public string  $key,
        public string  $message,
        public ?string $actionId = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['key'])) {
            throw new \InvalidArgumentException('RefundResponse: missing required field "key".');
        }

        return new self(
            key:      (string) $data['key'],
            message:  (string) ($data['message'] ?? ''),
            actionId: isset($data['action_id']) ? (string) $data['action_id'] : null,
        );
    }
}
