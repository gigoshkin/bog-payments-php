<?php

declare(strict_types=1);

namespace Bog\Payments\Dto\Request;

final readonly class CancelPreAuthRequest
{
    /**
     * @param string|null $description Reason for cancellation.
     */
    public function __construct(
        public ?string $description = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        if ($this->description !== null) {
            return ['description' => $this->description];
        }

        return [];
    }
}
