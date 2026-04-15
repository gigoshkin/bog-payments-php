<?php

declare(strict_types=1);

namespace Bog\Payments\Dto\Response;

use Bog\Payments\Enum\ActionType;
use Bog\Payments\Enum\PaymentStatusCode;

final readonly class OrderAction
{
    public function __construct(
        public string             $actionId,
        public ActionType         $type,
        public string             $requestChannel,
        public float              $amount,
        public string             $status,
        public \DateTimeImmutable $timestamp,
        public ?PaymentStatusCode $code,
        public ?string            $codeDescription,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $rawCode = isset($data['code']) ? (int) $data['code'] : null;

        return new self(
            actionId:        (string) ($data['action_id'] ?? ''),
            type:            ActionType::from((string) ($data['action'] ?? '')),
            requestChannel:  (string) ($data['request_channel'] ?? ''),
            amount:          (float) ($data['amount'] ?? 0),
            status:          (string) ($data['status'] ?? ''),
            timestamp:       new \DateTimeImmutable((string) ($data['zoned_action_date'] ?? 'now')),
            code:            $rawCode !== null ? PaymentStatusCode::tryFrom($rawCode) : null,
            codeDescription: isset($data['code_description']) ? (string) $data['code_description'] : null,
        );
    }

    public function isRefundFailure(): bool
    {
        return $this->code?->isRefundFailure() ?? false;
    }
}
