<?php

declare(strict_types=1);

namespace Bog\Payments\Tests\Sandbox;

use Bog\Payments\Dto\Request\BasketItem;
use Bog\Payments\Dto\Request\CancelPreAuthRequest;
use Bog\Payments\Dto\Request\ConfirmPreAuthRequest;
use Bog\Payments\Dto\Request\CreateOrderRequest;
use Bog\Payments\Dto\Request\RefundRequest;
use Bog\Payments\Dto\Request\SubscribeRequest;
use Bog\Payments\Dto\Response\CreateOrderResponse;
use Bog\Payments\Dto\Response\OrderAction;
use Bog\Payments\Enum\ActionType;
use Bog\Payments\Enum\CaptureMode;
use Bog\Payments\Enum\OrderStatus;
use Bog\Payments\Idempotency\IdempotencyKeyGenerator;

/**
 * Full end-to-end payment flow tests that guide you through the browser step.
 *
 * These tests create their own orders, print redirect URLs, wait for you to
 * complete the payment in a browser, then assert the results — no env vars
 * or pre-pasted order IDs needed.
 *
 * Run:
 *   ./vendor/bin/phpunit --testsuite sandbox --filter Interactive --testdox
 *
 * Requirements:
 *   - A real terminal (not CI — auto-skipped when STDIN is not a TTY)
 *   - BOG_SANDBOX_CLIENT_ID and BOG_SANDBOX_CLIENT_SECRET in .env.sandbox
 *
 * Test cards (any expiry date, any CVV):
 *   4000 0000 0000 0001  — Visa, succeeds
 *   5300 0000 0000 0001  — Mastercard, succeeds
 *   4000 0000 0000 0002  — Visa, declined (insufficient funds)
 */
final class InteractiveFlowTest extends SandboxTestCase
{
    private IdempotencyKeyGenerator $idempotencyKeyGen;

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->isInteractiveTerminal()) {
            $this->markTestSkipped('Interactive tests require a TTY. Run manually: --filter Interactive');
        }

        $this->idempotencyKeyGen = new IdempotencyKeyGenerator();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function isInteractiveTerminal(): bool
    {
        return function_exists('posix_isatty') && posix_isatty(STDIN);
    }

    /**
     * Print a prominent message directly to the terminal (bypasses PHPUnit buffering).
     */
    private function say(string $message): void
    {
        fwrite(STDOUT, $message);
    }

    /**
     * Print a payment prompt and wait for the user to press Enter.
     */
    private function promptPayment(CreateOrderResponse $order, string $card = '4000 0000 0000 0001'): void
    {
        $this->say("\n");
        $this->say("┌─────────────────────────────────────────────────────────────┐\n");
        $this->say("│  OPEN THIS URL IN YOUR BROWSER AND COMPLETE THE PAYMENT     │\n");
        $this->say("├─────────────────────────────────────────────────────────────┤\n");
        $this->say("│  {$order->redirectUrl}\n");
        $this->say("│\n");
        $this->say("│  Card:    {$card}\n");
        $this->say("│  Expiry:  any (e.g. 12/30)\n");
        $this->say("│  CVV:     any (e.g. 123)\n");
        $this->say("└─────────────────────────────────────────────────────────────┘\n");
        $this->say("Press ENTER when payment is complete... ");
        fgets(STDIN);
        $this->say("\n");

        // BOG needs ~15 s to process a payment before refund/action endpoints accept it
        $this->say("  Waiting 15s for BOG to process payment...\n");
        sleep(15);
    }

    /**
     * Poll order status until it leaves the transient states (created / processing),
     * or until the timeout is reached. Returns the final OrderDetails.
     *
     * BOG sandbox occasionally takes longer than 15 s to settle a payment.
     * Without polling the test would fail with an unexpected transient status.
     */
    private function waitForSettledStatus(string $orderId, int $timeoutSeconds = 45): \Bog\Payments\Dto\Response\OrderDetails
    {
        $transient = [OrderStatus::Created, OrderStatus::Processing];
        $client    = $this->makeClient();
        $deadline  = time() + $timeoutSeconds;

        do {
            $details = $client->getOrderDetails($orderId);
            if (!in_array($details->status, $transient, true)) {
                return $details;
            }
            $this->say("  (status still {$details->status->value}, polling...)\n");
            sleep(3);
        } while (time() < $deadline);

        return $details; // return whatever we have; assertion in the test will surface the failure
    }

    private function createOrder(float $amount = 20.0, CaptureMode $capture = CaptureMode::Automatic): CreateOrderResponse
    {
        return $this->makeClient()->createOrder(new CreateOrderRequest(
            callbackUrl:     'https://httpbin.org/post',
            totalAmount:     $amount,
            basket:          [new BasketItem('sku-interactive', 1, $amount, 'Interactive Test')],
            capture:         $capture,
            externalOrderId: 'INTERACTIVE-' . uniqid(),
        ));
    }

    // -------------------------------------------------------------------------
    // Refund flow
    // -------------------------------------------------------------------------

    public function test_full_refund_flow(): void
    {
        $order = $this->createOrder(20.0);

        $this->say("\n[TEST] Full refund flow — order {$order->orderId}\n");
        $this->promptPayment($order);

        $client  = $this->makeClient();
        $details = $this->waitForSettledStatus($order->orderId);

        self::assertNotSame(OrderStatus::Created, $details->status, 'Order must be paid');
        self::assertGreaterThan(0.0, $details->processedAmount);
        self::assertNotNull($details->paymentMethod);
        self::assertNotNull($details->maskedCard);
        self::assertNotNull($details->transactionId);
        self::assertNotNull($details->paymentCode);
        self::assertNotNull($details->cardType);

        $this->say("  ✔ Order status: {$details->status->value}, card: {$details->maskedCard}\n");

        // Full refund — sandbox requires explicit amount even for full refunds
        $refundAmount = $details->processedAmount;
        $idemKey      = $this->idempotencyKeyGen->generate();
        $response     = $client->refund($order->orderId, new RefundRequest($refundAmount), $idemKey);

        self::assertSame('request_received', $response->key);
        self::assertNotEmpty($response->actionId);

        // Idempotent retry
        usleep(500_000);
        $retry = $client->refund($order->orderId, new RefundRequest($refundAmount), $idemKey);
        self::assertSame($response->actionId, $retry->actionId, 'Idempotent refund must return same actionId');

        $this->say("  ✔ Refund accepted, actionId: {$response->actionId}\n");

        // Verify actions after refund
        usleep(1_000_000);
        $after = $client->getOrderDetails($order->orderId);

        $refundActions = array_filter(
            $after->actions,
            static fn(OrderAction $a) => $a->type === ActionType::Refund || $a->type === ActionType::PartialRefund,
        );

        self::assertNotEmpty($refundActions);
        self::assertContains($after->status, [
            OrderStatus::Refunded,
            OrderStatus::PartiallyRefunded,
            OrderStatus::Completed,
        ]);

        $this->say("  ✔ Status after refund: {$after->status->value}, actions: " . count($after->actions) . "\n");
    }

    public function test_partial_refund_flow(): void
    {
        $order = $this->createOrder(30.0);

        $this->say("\n[TEST] Partial refund flow — order {$order->orderId}\n");
        $this->promptPayment($order);

        $client  = $this->makeClient();
        $details = $this->waitForSettledStatus($order->orderId);

        self::assertNotSame(OrderStatus::Created, $details->status);
        $partial = round($details->requestedAmount / 2, 2);

        $idemKey  = $this->idempotencyKeyGen->generate();
        $response = $client->refund($order->orderId, new RefundRequest($partial), $idemKey);

        self::assertSame('request_received', $response->key);
        self::assertNotEmpty($response->actionId);

        $this->say("  ✔ Partial refund of {$partial} GEL accepted, actionId: {$response->actionId}\n");
    }

    // -------------------------------------------------------------------------
    // Pre-authorization confirm flow
    // -------------------------------------------------------------------------

    public function test_pre_auth_confirm_flow(): void
    {
        $order = $this->createOrder(50.0, CaptureMode::Manual);

        $this->say("\n[TEST] Pre-auth confirm flow — order {$order->orderId}\n");
        $this->promptPayment($order);

        $client  = $this->makeClient();
        $details = $this->waitForSettledStatus($order->orderId);

        self::assertSame(OrderStatus::PreAuthorizationBlocked, $details->status,
            'Paid manual-capture order must be blocked');
        self::assertGreaterThan(0.0, $details->requestedAmount);
        // BOG's transfer_amount for a blocked pre-auth order = the authorized/blocked amount, not 0

        $this->say("  ✔ Pre-auth status: {$details->status->value}, requested: {$details->requestedAmount}\n");

        // Full confirm — sandbox requires explicit amount even for full confirmation
        $confirmAmount = $details->requestedAmount;
        $idemKey       = $this->idempotencyKeyGen->generate();
        $response      = $client->confirmPreAuthorization(
            $order->orderId,
            new ConfirmPreAuthRequest(amount: $confirmAmount),
            $idemKey,
        );

        self::assertSame('request_received', $response->key);
        self::assertNotEmpty($response->actionId);

        // Idempotent retry
        usleep(500_000);
        $retry = $client->confirmPreAuthorization(
            $order->orderId,
            new ConfirmPreAuthRequest(amount: $confirmAmount),
            $idemKey,
        );
        self::assertSame($response->actionId, $retry->actionId);

        $this->say("  ✔ Confirm accepted, actionId: {$response->actionId}\n");

        // Verify status after confirm
        usleep(1_000_000);
        $after = $client->getOrderDetails($order->orderId);

        self::assertSame(OrderStatus::Completed, $after->status,
            'Order must be Completed after pre-auth confirm');
        self::assertGreaterThan(0.0, $after->processedAmount);

        $captureActions = array_filter(
            $after->actions,
            static fn(OrderAction $a) => in_array($a->type, [ActionType::Capture, ActionType::Authorize], true),
        );
        self::assertNotEmpty($captureActions);

        $this->say("  ✔ Status after confirm: {$after->status->value}, processed: {$after->processedAmount}\n");
    }

    public function test_pre_auth_partial_confirm_flow(): void
    {
        $order = $this->createOrder(60.0, CaptureMode::Manual);

        $this->say("\n[TEST] Pre-auth partial confirm flow — order {$order->orderId}\n");
        $this->promptPayment($order);

        $client  = $this->makeClient();
        $details = $this->waitForSettledStatus($order->orderId);

        self::assertSame(OrderStatus::PreAuthorizationBlocked, $details->status);
        $partial = round($details->requestedAmount / 2, 2);

        $idemKey  = $this->idempotencyKeyGen->generate();
        $response = $client->confirmPreAuthorization(
            $order->orderId,
            new ConfirmPreAuthRequest(amount: $partial, description: 'Partial confirm test'),
            $idemKey,
        );

        self::assertSame('request_received', $response->key);
        self::assertNotEmpty($response->actionId);

        $this->say("  ✔ Partial confirm of {$partial} GEL accepted, actionId: {$response->actionId}\n");
    }

    // -------------------------------------------------------------------------
    // Pre-authorization cancel flow
    // -------------------------------------------------------------------------

    public function test_pre_auth_cancel_flow(): void
    {
        $order = $this->createOrder(40.0, CaptureMode::Manual);

        $this->say("\n[TEST] Pre-auth cancel flow — order {$order->orderId}\n");
        $this->promptPayment($order);

        $client  = $this->makeClient();
        $details = $this->waitForSettledStatus($order->orderId);

        self::assertSame(OrderStatus::PreAuthorizationBlocked, $details->status);
        self::assertGreaterThan(0.0, $details->requestedAmount);

        $this->say("  ✔ Pre-auth confirmed, status: {$details->status->value}\n");

        $idemKey  = $this->idempotencyKeyGen->generate();
        $response = $client->cancelPreAuthorization(
            $order->orderId,
            new CancelPreAuthRequest('Cancel flow test'),
            $idemKey,
        );

        self::assertSame('request_received', $response->key);
        self::assertNotEmpty($response->actionId);

        $this->say("  ✔ Cancel accepted, actionId: {$response->actionId}\n");

        usleep(1_000_000);
        $after = $client->getOrderDetails($order->orderId);

        $cancelActions = array_filter(
            $after->actions,
            static fn(OrderAction $a) => $a->type === ActionType::CancelAuthorize,
        );
        self::assertNotEmpty($cancelActions, 'cancel_authorize action must appear after cancellation');

        $this->say("  ✔ Status after cancel: {$after->status->value}, processed: {$after->processedAmount}\n");
    }

    // -------------------------------------------------------------------------
    // Saved card flow
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // Saved card — offline automatic charging
    // -------------------------------------------------------------------------

    public function test_save_card_offline_and_charge_flow(): void
    {
        // The BOG sandbox Akamai CDN blocks PUT /payments/v1/orders/:id/subscriptions (returns 501).
        // saveCardAutomatic and saveCard work in the production environment only.
        $this->markTestSkipped('PUT /orders/:id/subscriptions returns 501 in BOG sandbox (Akamai restriction).');

        $order  = $this->createOrder(25.0);
        $client = $this->makeClient();

        // Must be called BEFORE payment — BOG links the card during checkout
        $client->saveCardAutomatic($order->orderId, $this->idempotencyKeyGen->generate());

        $this->say("\n[TEST] Save card (offline) and auto-charge flow — order {$order->orderId}\n");
        $this->promptPayment($order);

        // Charge the saved card automatically (no browser step)
        $charged = $client->chargeCard(
            $order->orderId,
            new SubscribeRequest(
                callbackUrl:     'https://httpbin.org/post',
                externalOrderId: 'AUTO-CHARGE-' . uniqid(),
            ),
            $this->idempotencyKeyGen->generate(),
        );

        self::assertNotEmpty($charged->orderId);
        self::assertNotSame($order->orderId, $charged->orderId);
        self::assertNotNull($charged->detailsUrl);

        $this->say("  ✔ Auto-charge succeeded, new orderId: {$charged->orderId}\n");

        $client->deleteCard($order->orderId);
        $this->say("  ✔ Card deleted\n");
    }

    // -------------------------------------------------------------------------
    // Saved card — user-present recurrent charging
    // -------------------------------------------------------------------------

    public function test_save_card_recurrent_and_create_order_flow(): void
    {
        // The BOG sandbox Akamai CDN blocks PUT /payments/v1/orders/:id/cards (returns 501).
        // saveCard and saveCardAutomatic work in the production environment only.
        $this->markTestSkipped('PUT /orders/:id/cards returns 501 in BOG sandbox (Akamai restriction).');

        $order  = $this->createOrder(25.0);
        $client = $this->makeClient();

        // Must be called BEFORE payment — BOG links the card during checkout
        $client->saveCard($order->orderId, $this->idempotencyKeyGen->generate());

        $this->say("\n[TEST] Save card (recurrent) and create recurrent order flow — order {$order->orderId}\n");
        $this->promptPayment($order);

        $recurrent = $client->createRecurrentOrder(
            $order->orderId,
            new \Bog\Payments\Dto\Request\CreateOrderRequest(
                callbackUrl:     'https://httpbin.org/post',
                totalAmount:     25.0,
                basket:          [new BasketItem('sku-recurrent', 1, 25.0, 'Recurrent Test')],
                externalOrderId: 'RECURRENT-' . uniqid(),
            ),
            $this->idempotencyKeyGen->generate(),
        );

        self::assertNotEmpty($recurrent->orderId);
        self::assertNotSame($order->orderId, $recurrent->orderId);
        self::assertStringContainsString('bog.ge', $recurrent->redirectUrl);

        $this->say("  ✔ Recurrent order created, orderId: {$recurrent->orderId}\n");
        $this->say("  (Customer would be redirected to {$recurrent->redirectUrl} to re-authenticate)\n");

        $client->deleteCard($order->orderId);
        $this->say("  ✔ Card deleted\n");
    }
}
