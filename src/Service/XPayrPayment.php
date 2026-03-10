<?php

declare(strict_types=1);

namespace XPayrPlugin\Service;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * XPayr asynchronous payment handler for Shopware 6.7+.
 *
 * Extends the new AbstractPaymentHandler API.
 * Creates a hosted checkout session via the XPayr API
 * and redirects the customer to the payment page.
 * On return or webhook, finalizes the transaction state.
 */
class XPayrPayment extends AbstractPaymentHandler
{
    private OrderTransactionStateHandler $transactionStateHandler;
    private SystemConfigService $systemConfigService;
    private EntityRepository $orderTransactionRepository;
    private EntityRepository $currencyRepository;
    private LoggerInterface $logger;
    private HttpClientInterface $httpClient;

    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        EntityRepository $orderTransactionRepository,
        EntityRepository $currencyRepository,
        SystemConfigService $systemConfigService,
        LoggerInterface $logger,
        HttpClientInterface $httpClient
    ) {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->currencyRepository = $currencyRepository;
        $this->systemConfigService = $systemConfigService;
        $this->logger = $logger;
        $this->httpClient = $httpClient;
    }

    /**
     * Declare supported payment handler types.
     * XPayr only handles standard payments (not recurring or refunds).
     */
    public function supports(
        PaymentHandlerType $type,
        string $paymentMethodId,
        Context $context
    ): bool {
        // Return true only for standard checkout (not recurring/refunds)
        return $type === PaymentHandlerType::CHECKOUT;
    }

    /**
     * Create an XPayr payment session and redirect the customer.
     *
     * Shopware 6.7 passes a PaymentTransactionStruct containing
     * the orderTransactionId and returnUrl. We retrieve order data
     * via the order_transaction repository.
     */
    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct
    ): ?RedirectResponse {

        $apiBaseUrl = (string) $this->systemConfigService->get('XPayrPlugin.config.apiBaseUrl');
        $secretKey = (string) $this->systemConfigService->get('XPayrPlugin.config.secretKey');
        $network = (string) $this->systemConfigService->get('XPayrPlugin.config.network');
        $currency = (string) $this->systemConfigService->get('XPayrPlugin.config.currency');

        $transactionId = $transaction->getOrderTransactionId();

        // Use a short custom return URL to prevent JWT truncation by external APIs
        $appUrl = rtrim((string) ($_ENV['APP_URL'] ?? 'https://3web3.site/sw'), '/');
        $successUrl = $appUrl . '/xpayr/return?tid=' . $transactionId . '&status=completed';
        $cancelUrl = $appUrl . '/xpayr/return?tid=' . $transactionId . '&status=cancelled';

        if (empty($apiBaseUrl) || empty($secretKey)) {
            throw PaymentException::asyncProcessInterrupted(
                $transactionId,
                'XPayr plugin is not configured. Please set API Base URL and Secret Key.'
            );
        }

        $orderData = $this->loadOrderData($transactionId, $context);
        $orderNumber = $orderData['orderNumber'] ?? '';
        $amountTotal = (float) ($orderData['amountTotal'] ?? 0);
        $orderCurrency = $orderData['currencyIso'] ?? 'EUR';
        $customerEmail = $orderData['customerEmail'] ?? '';

        $payload = [
            'amount' => number_format($amountTotal, 2, '.', ''),
            'currency' => !empty($currency) ? strtoupper($currency) : 'USDC',
            'network' => !empty($network) ? $network : 'bsc-testnet',
            'order_id' => 'SW6-' . $orderNumber,
            'description' => sprintf('Shopware Order #%s', $orderNumber),
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'ipn_callback_url' => $appUrl . '/xpayr/webhook',
            'metadata' => [
                'source' => 'shopware6',
                'order_number' => $orderNumber,
                'transaction_id' => $transactionId,
                'order_currency' => $orderCurrency,
                'customer_email' => $customerEmail,
            ],
        ];

        $this->log('Creating XPayr payment session', $payload);

        $apiResponse = $this->apiRequest($apiBaseUrl, $secretKey, 'POST', '/payments', $payload);

        if (empty($apiResponse['success'])) {
            $errorMsg = $apiResponse['message'] ?? 'Unknown API error';
            $this->log('XPayr API error: ' . $errorMsg, $apiResponse, true);

            throw PaymentException::asyncProcessInterrupted(
                $transactionId,
                'XPayr payment session creation failed: ' . $errorMsg
            );
        }

        $paymentUrl = (string) ($apiResponse['data']['payment_url'] ?? '');
        $sessionId = (string) ($apiResponse['data']['id'] ?? $apiResponse['data']['session_id'] ?? '');

        if (empty($paymentUrl)) {
            $this->log('XPayr: no payment_url in response', $apiResponse, true);

            throw PaymentException::asyncProcessInterrupted(
                $transactionId,
                'XPayr API returned no payment URL.'
            );
        }

        $this->log('Redirecting to XPayr checkout', [
            'session_id' => $sessionId,
            'payment_url' => $paymentUrl,
        ]);

        $this->transactionStateHandler->process($transactionId, $context);

        return new RedirectResponse($paymentUrl);
    }

    /**
     * Called when the customer returns from the XPayr checkout.
     *
     * The actual payment confirmation happens via webhook.
     * Here we check query parameters and update state if complete.
     */
    public function finalize(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context
    ): void {
        $transactionId = $transaction->getOrderTransactionId();
        $paymentStatus = $request->query->get('payment_status', '');

        $this->log('Customer returned from XPayr', [
            'transaction_id' => $transactionId,
            'payment_status' => $paymentStatus,
        ]);

        if ($paymentStatus === 'completed' || $paymentStatus === 'confirmed') {
            $this->transactionStateHandler->paid($transactionId, $context);
        } elseif ($paymentStatus === 'failed' || $paymentStatus === 'expired') {
            $this->transactionStateHandler->fail($transactionId, $context);
        }
    }

    /**
     * Load order details from the order_transaction repository.
     *
     * We need the order object to get amount, currency and customer email.
     * Shopware 6.7's PaymentTransactionStruct only provides
     * orderTransactionId and returnUrl.
     *
     * @return array{orderNumber: string, amountTotal: float, currencyIso: string, customerEmail: string}
     */
    private function loadOrderData(string $orderTransactionId, Context $context): array
    {
        $criteria = new Criteria([$orderTransactionId]);
        $criteria->addAssociation('order');
        $criteria->addAssociation('order.currency');
        $criteria->addAssociation('order.orderCustomer');

        $result = $this->orderTransactionRepository->search($criteria, $context);
        $orderTransaction = $result->first();

        if (!$orderTransaction) {
            return [
                'orderNumber' => 'UNKNOWN',
                'amountTotal' => 0.0,
                'currencyIso' => 'EUR',
                'customerEmail' => '',
            ];
        }

        /** @var OrderEntity|null $order */
        $order = $orderTransaction->getOrder();

        if (!$order) {
            return [
                'orderNumber' => 'UNKNOWN',
                'amountTotal' => 0.0,
                'currencyIso' => 'EUR',
                'customerEmail' => '',
            ];
        }

        $currencyIso = 'EUR';
        if ($order->getCurrency()) {
            $currencyIso = $order->getCurrency()->getIsoCode();
        }

        return [
            'orderNumber' => $order->getOrderNumber() ?? '',
            'amountTotal' => $order->getAmountTotal(),
            'currencyIso' => $currencyIso,
            'customerEmail' => $order->getOrderCustomer()?->getEmail() ?? '',
        ];
    }

    /**
     * Make an HTTP request to the XPayr API using Symfony HttpClient.
     *
     * @param string $apiBaseUrl API base URL
     * @param string $secretKey  Bearer token
     * @param string $method     HTTP method
     * @param string $path       API path
     * @param array  $payload    Request body
     * @return array Parsed response
     */
    private function apiRequest(
        string $apiBaseUrl,
        string $secretKey,
        string $method,
        string $path,
        array $payload = []
    ): array {
        $url = rtrim($apiBaseUrl, '/') . '/' . ltrim($path, '/');

        try {
            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $secretKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'timeout' => 30,
            ];

            if (!empty($payload)) {
                $options['json'] = $payload;
            }

            $response = $this->httpClient->request(strtoupper($method), $url, $options);
            $httpCode = $response->getStatusCode();
            $raw = $response->getContent(false);

            $json = json_decode($raw, true);

            if ($httpCode < 200 || $httpCode >= 300 || !is_array($json)) {
                $msg = is_array($json) && !empty($json['error']['message'])
                    ? (string) $json['error']['message']
                    : 'HTTP ' . $httpCode;
                return ['success' => false, 'message' => $msg, 'raw' => $raw];
            }

            return ['success' => true, 'data' => $json, 'raw' => $raw];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'HTTP request failed: ' . $e->getMessage()];
        }
    }

    /**
     * Log a message if debug mode is enabled.
     */
    private function log(string $message, array $context = [], bool $force = false): void
    {
        $debug = (bool) $this->systemConfigService->get('XPayrPlugin.config.debugEnabled');

        if ($force || $debug) {
            $this->logger->info('[XPayr] ' . $message, $context);
        }
    }
}

