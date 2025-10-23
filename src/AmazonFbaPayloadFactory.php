<?php

namespace App;

use App\Data\AbstractOrder;
use App\Data\BuyerInterface;
use RuntimeException;

/**
 * Builds request payloads for the Selling Partner FBA Outbound CreateFulfillmentOrder endpoint.
 */
class AmazonFbaPayloadFactory
{
    private string $marketplaceId;
    private string $defaultShippingSpeedCategory;
    private string $fulfillmentAction;
    private string $fulfillmentPolicy;
    private string $sellerFulfillmentOrderIdPrefix;
    private bool $includeNotificationEmails;
    /** @var array<string,string> */
    private array $shippingSpeedCategoryMap;
    private string $currencyFallback;

    /**
     * @param array<string,mixed> $options
     */
    public function __construct(string $marketplaceId, array $options = [])
    {
        $this->marketplaceId = $marketplaceId;
        $this->defaultShippingSpeedCategory = (string)($options['default_shipping_speed_category'] ?? 'Standard');
        $this->fulfillmentAction = (string)($options['fulfillment_action'] ?? 'Ship');
        $this->fulfillmentPolicy = (string)($options['fulfillment_policy'] ?? 'FillOrKill');
        $this->sellerFulfillmentOrderIdPrefix = $this->sanitizePrefix((string)($options['seller_fulfillment_order_id_prefix'] ?? 'VALIGARA'));
        $this->includeNotificationEmails = (bool)($options['include_notification_emails'] ?? true);
        $this->shippingSpeedCategoryMap = (array)($options['shipping_speed_category_map'] ?? []);
        $this->currencyFallback = (string)($options['currency_fallback'] ?? 'USD');
    }

    /**
     * @return array<string,mixed>
     */
    public function build(AbstractOrder $order, BuyerInterface $buyer): array
    {
        if (!is_array($order->data) || $order->data === []) {
            throw new RuntimeException('Order payload is empty. Please load the order before shipping.');
        }

        $orderData = $order->data;
        $items = $this->buildItems($order, $orderData);
        $destinationAddress = $this->buildDestinationAddress($orderData, $buyer);

        $payload = [
            'marketplaceId' => $this->marketplaceId,
            'sellerFulfillmentOrderId' => $this->generateSellerFulfillmentOrderId($order),
            'displayableOrderId' => $this->extractDisplayableOrderId($order, $orderData),
            'displayableOrderDate' => $this->extractDisplayableOrderDate($orderData),
            'displayableOrderComment' => $this->extractDisplayableOrderComment($orderData),
            'shippingSpeedCategory' => $this->determineShippingSpeedCategory($orderData),
            'destinationAddress' => $destinationAddress,
            'items' => $items,
            'fulfillmentAction' => $this->fulfillmentAction,
            'fulfillmentPolicy' => $this->fulfillmentPolicy,
        ];

        $notificationEmails = $this->extractNotificationEmails($buyer, $orderData);
        if ($notificationEmails !== []) {
            $payload['notificationEmails'] = $notificationEmails;
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $orderData
     * @return array<string,mixed>
     */
    private function buildDestinationAddress(array $orderData, BuyerInterface $buyer): array
    {
        $addressLines = $this->extractAddressLines($orderData);
        $addressLine1 = $orderData['shipping_street'] ?? ($addressLines[1] ?? $addressLines[0] ?? '');
        $addressLine2 = $addressLines[1] ?? '';
        $addressLine3 = $addressLines[2] ?? '';

        if (trim($addressLine1) === '') {
            throw new RuntimeException('Destination address requires at least one street line.');
        }

        $countryCode = $orderData['shipping_country'] ?? ($buyer['country_code'] ?? $buyer['country_code3'] ?? '');
        if ($countryCode === '') {
            throw new RuntimeException('Destination country code is required.');
        }

        $phone = $orderData['shipping_phone'] ?? ($buyer['phone'] ?? '');
        $city = $orderData['shipping_city'] ?? ($buyer['city'] ?? '');
        if ($city === '') {
            throw new RuntimeException('Destination city is required.');
        }

        return array_filter([
            'name' => $orderData['buyer_name'] ?? ($buyer['name'] ?? $buyer['shop_username'] ?? 'Amazon Customer'),
            'addressLine1' => $addressLine1,
            'addressLine2' => $addressLine2 !== $addressLine1 ? $addressLine2 : '',
            'addressLine3' => $addressLine3,
            'city' => $city,
            'districtOrCounty' => $orderData['shipping_county'] ?? '',
            'stateOrRegion' => $orderData['shipping_state'] ?? '',
            'postalCode' => $orderData['shipping_zip'] ?? '',
            'countryCode' => $countryCode,
            'phone' => $phone,
        ], static fn($value) => $value !== '' && $value !== null);
    }

    /**
     * @param array<string,mixed> $orderData
     * @return array<int,array<string,mixed>>
     */
    private function buildItems(AbstractOrder $order, array $orderData): array
    {
        if (empty($orderData['products']) || !is_array($orderData['products'])) {
            throw new RuntimeException('Order does not contain any products for fulfillment.');
        }

        $currency = $orderData['currency'] ?? $this->currencyFallback;
        $items = [];
        foreach ($orderData['products'] as $index => $product) {
            if (!is_array($product)) {
                continue;
            }

            $sku = (string)($product['sku'] ?? $product['product_code'] ?? '');
            if ($sku === '') {
                throw new RuntimeException('Product SKU is required for fulfillment.');
            }

            $quantity = (int)($product['ammount'] ?? $product['quantity'] ?? 1);
            if ($quantity <= 0) {
                throw new RuntimeException('Product quantity must be greater than zero for SKU ' . $sku);
            }

            $declaredValue = $this->formatMoney($product['buying_price'] ?? $product['original_price'] ?? $product['price'] ?? null);

            $item = [
                'sellerSku' => $sku,
                'sellerFulfillmentOrderItemId' => $this->generateItemIdentifier($order, $index, $sku),
                'quantity' => $quantity,
            ];

            $displayableComment = (string)($product['comment'] ?? '');
            if ($displayableComment !== '') {
                $item['displayableComment'] = $displayableComment;
            }

            if ($declaredValue !== null) {
                $item['perUnitDeclaredValue'] = [
                    'currencyCode' => $currency,
                    'value' => $declaredValue,
                ];
            }

            $items[] = $item;
        }

        if ($items === []) {
            throw new RuntimeException('No valid products were found on the order.');
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $orderData
     * @return array<int,string>
     */
    private function extractAddressLines(array $orderData): array
    {
        $raw = (string)($orderData['shipping_adress'] ?? '');
        if ($raw === '') {
            return [];
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $raw);
        $pieces = array_filter(array_map('trim', explode("\n", $normalized)));

        return array_values($pieces);
    }

    private function extractDisplayableOrderId(AbstractOrder $order, array $orderData): string
    {
        $candidates = [
            (string)($orderData['order_unique'] ?? ''),
            (string)($orderData['order_id'] ?? ''),
            (string)$order->getOrderId(),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return (string)$order->getOrderId();
    }

    private function extractDisplayableOrderDate(array $orderData): string
    {
        $date = (string)($orderData['order_date'] ?? '');
        if ($date !== '') {
            try {
                return (new \DateTimeImmutable($date))->format(\DateTimeInterface::ATOM);
            } catch (\Exception $exception) {
                // fall back to current time below
            }
        }

        return (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
    }

    private function extractDisplayableOrderComment(array $orderData): string
    {
        $comment = (string)($orderData['comments'] ?? $orderData['comment'] ?? '');
        if ($comment !== '') {
            return $comment;
        }

        return 'Automated fulfillment request';
    }

    /**
     * @param array<string,mixed> $orderData
     * @return array<int,string>
     */
    private function extractNotificationEmails(BuyerInterface $buyer, array $orderData): array
    {
        $emails = [];
        if (isset($orderData['buyer_email']) && filter_var($orderData['buyer_email'], FILTER_VALIDATE_EMAIL)) {
            $emails[] = $orderData['buyer_email'];
        }

        $buyerEmail = $buyer['email'] ?? null;
        if (is_string($buyerEmail) && filter_var($buyerEmail, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $buyerEmail;
        }

        return array_values(array_unique($emails));
    }

    /**
     * @param array<string,mixed> $orderData
     */
    private function determineShippingSpeedCategory(array $orderData): string
    {
        $shippingType = strtolower((string)($orderData['shiping_name'] ?? $orderData['shipping_method'] ?? ''));
        foreach ($this->shippingSpeedCategoryMap as $key => $category) {
            if (stripos($shippingType, $key) !== false) {
                return (string)$category;
            }
        }

        return $this->defaultShippingSpeedCategory;
    }

    private function generateSellerFulfillmentOrderId(AbstractOrder $order): string
    {
        $timestamp = gmdate('YmdHis');
        $raw = $this->sellerFulfillmentOrderIdPrefix . '-' . $order->getOrderId() . '-' . $timestamp;

        return substr($raw, 0, 40);
    }

    private function generateItemIdentifier(AbstractOrder $order, int $index, string $sku): string
    {
        $base = $order->getOrderId() . '-' . $index . '-' . preg_replace('/[^A-Za-z0-9]/', '', $sku);

        return substr($base, 0, 40);
    }

    private function sanitizePrefix(string $prefix): string
    {
        $clean = preg_replace('/[^A-Za-z0-9]/', '', strtoupper($prefix));
        if ($clean === '') {
            return 'VALIGARA';
        }

        return substr($clean, 0, 20);
    }

    private function formatMoney(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $floatValue = (float)$value;
        if ($floatValue <= 0) {
            return null;
        }

        return number_format($floatValue, 2, '.', '');
    }
}
