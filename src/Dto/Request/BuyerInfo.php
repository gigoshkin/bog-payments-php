<?php

declare(strict_types=1);

namespace Bog\Payments\Dto\Request;

final readonly class BuyerInfo
{
    public function __construct(
        public ?string $fullName    = null,
        public ?string $maskedEmail = null,
        public ?string $maskedPhone = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [];

        if ($this->fullName !== null) {
            $data['full_name'] = $this->fullName;
        }

        if ($this->maskedEmail !== null) {
            $data['masked_email'] = $this->maskedEmail;
        }

        if ($this->maskedPhone !== null) {
            $data['masked_phone'] = $this->maskedPhone;
        }

        return $data;
    }
}
