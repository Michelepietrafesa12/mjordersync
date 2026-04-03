<?php
/**
 * MJ Order Sync - Builds the webhook JSON payload from an Order object
 *
 * @author    Michele (pietrafesamichele.it)
 * @license   AFL 3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class MjOrderSyncOrderPayloadBuilder
{
    /**
     * Build the full payload array for a given order and event type.
     *
     * @param Order  $order
     * @param string $event  e.g. 'order.created', 'order.updated'
     *
     * @return array
     */
    public function build(Order $order, string $event): array
    {
        $customer = new Customer($order->id_customer);
        $deliveryAddress = new Address($order->id_address_delivery);
        $invoiceAddress = new Address($order->id_address_invoice);
        $carrier = new Carrier($order->id_carrier);
        $orderState = $order->getCurrentOrderState();
        $currency = new Currency($order->id_currency);

        return [
            'event'     => $event,
            'timestamp' => date('c'),
            'shop_url'  => Tools::getShopDomainSsl(true),
            'order'     => $this->buildOrder($order),
            'customer'  => $this->buildCustomer($customer),
            'addresses' => [
                'delivery' => $this->buildAddress($deliveryAddress),
                'invoice'  => $this->buildAddress($invoiceAddress),
            ],
            'products'  => $this->buildProducts($order),
            'totals'    => $this->buildTotals($order, $currency),
            'payment'   => [
                'method' => $order->payment,
                'module' => $order->module,
            ],
            'status'    => $this->buildStatus($orderState),
            'carrier'   => $this->buildCarrier($order, $carrier),
        ];
    }

    private function buildOrder(Order $order): array
    {
        return [
            'id'            => (int) $order->id,
            'reference'     => $order->reference,
            'date_add'      => $order->date_add,
            'date_upd'      => $order->date_upd,
            'gift'          => (bool) $order->gift,
            'gift_message'  => $order->gift_message ?: '',
            'note'          => $order->note ?: '',
        ];
    }

    private function buildCustomer(Customer $customer): array
    {
        return [
            'id'           => (int) $customer->id,
            'firstname'    => $customer->firstname,
            'lastname'     => $customer->lastname,
            'email'        => $customer->email,
            'birthday'     => $customer->birthday ?: '',
            'orders_count' => (int) Order::getCustomerNbOrders($customer->id),
        ];
    }

    private function buildAddress(Address $address): array
    {
        $country = new Country($address->id_country);
        $countryIso = $country->iso_code;

        return [
            'firstname'  => $address->firstname,
            'lastname'   => $address->lastname,
            'company'    => $address->company ?: '',
            'vat_number' => $address->vat_number ?: '',
            'address1'   => $address->address1,
            'address2'   => $address->address2,
            'postcode'   => $address->postcode,
            'city'       => $address->city,
            'country'    => $countryIso,
            'phone'      => $address->phone ?: $address->phone_mobile,
        ];
    }

    private function buildProducts(Order $order): array
    {
        $products = $order->getProducts();
        $result = [];

        foreach ($products as $product) {
            $result[] = [
                'id_product'           => (int) $product['product_id'],
                'reference'            => $product['product_reference'],
                'ean13'                => $product['product_ean13'] ?? '',
                'name'                 => $product['product_name'],
                'quantity'             => (int) $product['product_quantity'],
                'qty_refunded'         => (int) ($product['product_quantity_refunded'] ?? 0),
                'price_unit_tax_excl'  => (float) $product['unit_price_tax_excl'],
                'price_unit_tax_incl'  => (float) $product['unit_price_tax_incl'],
                'price_total_tax_incl' => (float) $product['total_price_tax_incl'],
                'tax_rate'             => (float) $product['tax_rate'],
            ];
        }

        return $result;
    }

    private function buildTotals(Order $order, Currency $currency): array
    {
        return [
            'total_paid_tax_incl'     => (float) $order->total_paid_tax_incl,
            'total_paid_real'         => (float) $order->total_paid_real,
            'total_products'          => (float) $order->total_products,
            'total_products_wt'       => (float) $order->total_products_wt,
            'total_shipping_tax_incl' => (float) $order->total_shipping_tax_incl,
            'total_shipping_tax_excl' => (float) $order->total_shipping_tax_excl,
            'total_discounts'         => (float) $order->total_discounts_tax_incl,
            'total_wrapping_tax_incl' => (float) $order->total_wrapping_tax_incl,
            'conversion_rate'         => (float) $order->conversion_rate,
            'currency_iso'            => $currency->iso_code,
        ];
    }

    private function buildStatus(?OrderState $orderState): array
    {
        if (!$orderState) {
            return [
                'id'      => 0,
                'name'    => '',
                'paid'    => false,
                'shipped' => false,
            ];
        }

        return [
            'id'      => (int) $orderState->id,
            'name'    => $orderState->name[Configuration::get('PS_LANG_DEFAULT')] ?? '',
            'paid'    => (bool) $orderState->paid,
            'shipped' => (bool) $orderState->shipped,
        ];
    }

    private function buildCarrier(Order $order, Carrier $carrier): array
    {
        $orderCarrier = $order->getShipping();
        $trackingNumber = '';
        $weight = 0.0;

        if (!empty($orderCarrier)) {
            $first = reset($orderCarrier);
            $trackingNumber = $first['tracking_number'] ?? '';
            $weight = (float) ($first['weight'] ?? 0);
        }

        return [
            'id'              => (int) $carrier->id,
            'name'            => $carrier->name,
            'tracking_number' => $trackingNumber,
            'weight'          => $weight,
        ];
    }
}
