<?php

declare(strict_types=1);

namespace XPayrPlugin\Storefront\Controller;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Psr\Log\LoggerInterface;

/**
 * Handles XPayr webhook callbacks and payment status notifications.
 *
 * Verifies the X-XPayr-Signature header, parses the event payload,
 * and transitions the order transaction to the appropriate state.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class WebhookController extends StorefrontController
{
    private OrderTransactionStateHandler $transactionStateHandler;
    private EntityRepository $orderTransactionRepository;
    private SystemConfigService $systemConfigService;
    private LoggerInterface $logger;

    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        EntityRepository $orderTransactionRepository,
        SystemConfigService $systemConfigService,
        LoggerInterface $logger
    ) {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->systemConfigService = $systemConfigService;
        $this->logger = $logger;
    }

    /**
     * Receive webhook events from XPayr.
     *
     * Endpoint: POST /xpayr/webhook
     *
     * Verifies signature, extracts payment status and transaction ID,
     * and updates the Shopware order transaction state accordingly.
     */
    #[Route(
        path: '/xpayr/webhook',
        name: 'frontend.xpayr.webhook',
        defaults: ['auth_required' => false, 'csrf_protected' => false],
        options: ['seo' => 'false'],
        methods: ['POST']
    )]
    public function webhook(Request $request, SalesChannelContext $salesChannelContext): Response
    {
        $rawBody = $request->getContent();

        $this->log('Webhook received', ['body_length' => strlen($rawBody)]);

        $webhookSecret = (string) $this->systemConfigService->get('XPayrPlugin.config.webhookSecret');

        if (!empty($webhookSecret)) {
            $signature = $request->headers->get('X-XPayr-Signature', '');
            $expectedSignature = hash_hmac('sha256', $rawBody, $webhookSecret);

            if (!hash_equals($expectedSignature, (string) $signature)) {
                $this->log('Webhook signature mismatch', [
                    'expected' => $expectedSignature,
                    'received' => $signature,
                ], true);

                return new JsonResponse(['error' => 'Invalid signature'], Response::HTTP_UNAUTHORIZED);
            }
        }

        $payload = json_decode($rawBody, true);

        if (!is_array($payload)) {
            $this->log('Webhook: invalid JSON body', [], true);
            return new JsonResponse(['error' => 'Invalid payload'], Response::HTTP_BAD_REQUEST);
        }

        $event = (string) ($payload['event'] ?? '');
        $transactionId = (string) ($payload['metadata']['transaction_id'] ?? '');
        $sessionId = (string) ($payload['session_id'] ?? $payload['id'] ?? '');

        $this->log('Webhook event', [
            'event' => $event,
            'session_id' => $sessionId,
            'transaction_id' => $transactionId,
        ]);

        if (empty($transactionId)) {
            $this->log('Webhook: missing transaction_id in metadata', $payload, true);
            return new JsonResponse(['error' => 'Missing transaction_id'], Response::HTTP_BAD_REQUEST);
        }

        $context = $salesChannelContext->getContext();

        try {
            $criteria = new Criteria([$transactionId]);
            $result = $this->orderTransactionRepository->search($criteria, $context);

            if ($result->getTotal() === 0) {
                $this->log('Webhook: transaction not found', ['id' => $transactionId], true);
                return new JsonResponse(['error' => 'Transaction not found'], Response::HTTP_NOT_FOUND);
            }

            switch ($event) {
                case 'payment.completed':
                case 'payment.confirmed':
                    $this->transactionStateHandler->paid($transactionId, $context);
                    $this->log('Transaction marked as paid', ['id' => $transactionId]);
                    break;

                case 'payment.failed':
                case 'payment.expired':
                    $this->transactionStateHandler->fail($transactionId, $context);
                    $this->log('Transaction marked as failed', ['id' => $transactionId]);
                    break;

                case 'payment.pending':
                case 'payment.processing':
                    $this->log('Transaction still processing', ['id' => $transactionId]);
                    break;

                default:
                    $this->log('Unknown webhook event: ' . $event, ['id' => $transactionId]);
                    break;
            }
        } catch (\Exception $e) {
            $this->log('Webhook processing error: ' . $e->getMessage(), [
                'transaction_id' => $transactionId,
            ], true);

            return new JsonResponse(
                ['error' => 'Processing error'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return new JsonResponse(['status' => 'ok']);
    }

    /**
     * Custom lightweight return route to bypass Shopware's long JWT url constraints.
     * XPayr will redirect customer here, and we redirect internally to the shop templates.
     */
    #[Route(
        path: '/xpayr/return',
        name: 'frontend.xpayr.return',
        defaults: ['auth_required' => false, 'csrf_protected' => false],
        options: ['seo' => 'false'],
        methods: ['GET']
    )]
    public function returnAction(Request $request, SalesChannelContext $salesChannelContext): Response
    {
        $transactionId = (string) $request->query->get('tid');
        $status = (string) $request->query->get('status', 'cancelled');

        $context = $salesChannelContext->getContext();

        if (empty($transactionId)) {
            return $this->redirectToRoute('frontend.home.page');
        }

        try {
            $criteria = new Criteria([$transactionId]);
            $criteria->addAssociation('order');

            $result = $this->orderTransactionRepository->search($criteria, $context);

            if ($result->getTotal() === 0) {
                return $this->redirectToRoute('frontend.home.page');
            }

            $transaction = $result->first();
            $order = $transaction->getOrder();

            if (!$order) {
                return $this->redirectToRoute('frontend.home.page');
            }

            $orderId = $order->getId();

            if ($status === 'completed' || $status === 'confirmed') {
                return $this->redirectToRoute('frontend.checkout.finish.page', [
                    'orderId' => $orderId
                ]);
            }

            return $this->redirectToRoute('frontend.account.order.edit.page', [
                'orderId' => $orderId,
                'error-code' => 'XPAYR_PAYMENT_CANCELLED'
            ]);

        } catch (\Exception $e) {
            $this->log('XPayr return error: ' . $e->getMessage());
            return $this->redirectToRoute('frontend.home.page');
        }
    }

    /**
     * Log a message if debug mode is enabled.
     */
    private function log(string $message, array $context = [], bool $force = false): void
    {
        $debug = (bool) $this->systemConfigService->get('XPayrPlugin.config.debugEnabled');

        if ($force || $debug) {
            $this->logger->info('[XPayr Webhook] ' . $message, $context);
        }
    }
}
