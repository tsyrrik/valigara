<?php

namespace App;

use App\Data\AbstractOrder;
use App\Data\BuyerInterface;
use App\SpApi\LwaAccessTokenProvider;
use App\SpApi\SellingPartnerHttpClient;
use RuntimeException;
use Throwable;

class AmazonFbaShippingService implements ShippingServiceInterface
{
    private AmazonFbaPayloadFactory $payloadFactory;
    private ?SellingPartnerHttpClient $client;
    /** @var array<string,mixed> */
    private array $config;
    private bool $sandboxMode;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(
        array $config,
        ?AmazonFbaPayloadFactory $payloadFactory = null,
        ?SellingPartnerHttpClient $client = null
    ) {
        $this->config = $config;
        $this->sandboxMode = (bool)($config['sandbox'] ?? false);
        $this->payloadFactory = $payloadFactory ?? $this->createPayloadFactory($config);
        $this->client = $this->sandboxMode ? null : ($client ?? $this->createHttpClient($config));
    }

    public function ship(AbstractOrder $order, BuyerInterface $buyer): string
    {
        if (!is_array($order->data) || $order->data === []) {
            $order->load();
        }

        $payload = $this->payloadFactory->build($order, $buyer);

        if ($this->sandboxMode) {
            return $this->simulateShipment($payload);
        }

        if ($this->client === null) {
            throw new RuntimeException('Selling Partner API client is not configured.');
        }

        try {
            $this->client->request(
                'POST',
                '/fba/outbound/2020-07-01/fulfillmentOrders',
                [],
                $payload
            );

            $response = $this->client->request(
                'GET',
                '/fba/outbound/2020-07-01/fulfillmentOrders/' . rawurlencode($payload['sellerFulfillmentOrderId'])
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('Unable to submit fulfillment request: ' . $exception->getMessage(), 0, $exception);
        }

        $trackingNumber = $this->extractTrackingNumber($response['body'] ?? null);
        if ($trackingNumber === null) {
            throw new RuntimeException('Tracking number is not available for fulfillment order ' . $payload['sellerFulfillmentOrderId']);
        }

        return $trackingNumber;
    }

    /**
     * @param array<string,mixed>|null $responseBody
     */
    private function extractTrackingNumber(?array $responseBody): ?string
    {
        if ($responseBody === null) {
            return null;
        }

        $payload = $responseBody['payload'] ?? $responseBody;
        if (!is_array($payload)) {
            return null;
        }

        if (isset($payload['trackingNumber']) && is_string($payload['trackingNumber'])) {
            return $payload['trackingNumber'];
        }

        if (isset($payload['shipments']) && is_array($payload['shipments'])) {
            foreach ($payload['shipments'] as $shipment) {
                if (!is_array($shipment) || empty($shipment['packages']) || !is_array($shipment['packages'])) {
                    continue;
                }
                foreach ($shipment['packages'] as $package) {
                    if (is_array($package) && isset($package['trackingNumber']) && is_string($package['trackingNumber'])) {
                        return $package['trackingNumber'];
                    }
                }
            }
        }

        if (isset($payload['fulfillmentShipment']) && is_array($payload['fulfillmentShipment'])) {
            $shipment = $payload['fulfillmentShipment'];
            if (isset($shipment['packages']) && is_array($shipment['packages'])) {
                foreach ($shipment['packages'] as $package) {
                    if (is_array($package) && isset($package['trackingNumber']) && is_string($package['trackingNumber'])) {
                        return $package['trackingNumber'];
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $config
     */
    private function createHttpClient(array $config): SellingPartnerHttpClient
    {
        foreach (['endpoint', 'region', 'aws_access_key_id', 'aws_secret_access_key', 'lwa_client_id', 'lwa_client_secret', 'lwa_refresh_token'] as $key) {
            if (empty($config[$key]) || !is_string($config[$key])) {
                throw new RuntimeException('Missing Selling Partner API configuration key: ' . $key);
            }
        }

        $tokenProvider = new LwaAccessTokenProvider(
            $config['lwa_client_id'],
            $config['lwa_client_secret'],
            $config['lwa_refresh_token'],
            $config['lwa_endpoint'] ?? 'https://api.amazon.com/auth/o2/token'
        );

        return new SellingPartnerHttpClient(
            $config['endpoint'],
            $config['region'],
            $config['aws_access_key_id'],
            $config['aws_secret_access_key'],
            $tokenProvider,
            $config['security_token'] ?? null,
            $config['user_agent'] ?? 'ValigaraTestAssignment/1.0 (+https://valigara.com)'
        );
    }

    /**
     * @param array<string,mixed> $config
     */
    private function createPayloadFactory(array $config): AmazonFbaPayloadFactory
    {
        if (empty($config['marketplace_id']) || !is_string($config['marketplace_id'])) {
            throw new RuntimeException('Amazon marketplace_id configuration is required.');
        }

        $options = (array)($config['payload_options'] ?? []);

        return new AmazonFbaPayloadFactory($config['marketplace_id'], $options);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function simulateShipment(array $payload): string
    {
        $seed = $payload['sellerFulfillmentOrderId'] ?? uniqid('FBA', true);

        return 'TBA' . strtoupper(substr(md5((string)$seed), 0, 12));
    }
}

