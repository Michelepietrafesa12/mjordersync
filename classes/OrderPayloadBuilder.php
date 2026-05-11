<?php
/**
 * MJ Order Sync - Order payload builder.
 *
 * Turns a PrestaShop Order into the JSON-serializable array documented in
 * README.md. Defensive about missing related objects (guest customers,
 * deleted addresses/carriers) so a partially-broken record still produces
 * a deliverable payload — the receiver can decide what to do, we do not
 * want to drop events at the source.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class MjOrderSyncOrderPayloadBuilder
{
    public function build(Order $order, string $event): array
    {
        $idLang = (int) $order->id_lang ?: (int) Configuration::get('PS_LANG_DEFAULT');

        $customer        = new Customer((int) $order->id_customer);
        $addressDelivery = new Address((int) $order->id_address_delivery);
        $addressInvoice  = new Address((int) $order->id_address_invoice);
        $carrier         = new Carrier((int) $order->id_carrier, $idLang);
        $currency        = new Currency((int) $order->id_currency);
        $orderState      = new OrderState((int) $order->getCurrentState(), $idLang);

        return [
            'event'     => $event,
            'timestamp' => date('c'),
            'shop_url'  => Tools::getShopDomainSsl(true),
            'order'     => [
                'id'        => (int) $order->id,
                'reference' => (string) $order->reference,
                'date_add'  => (string) $order->date_add,
                'date_upd'  => (string) $order->date_upd,
            ],
            'customer'  => $this->buildCustomer($customer),
            'addresses' => [
                'delivery' => $this->buildAddress($addressDelivery),
                'invoice'  => $this->buildAddress($addressInvoice),
            ],
            'products'  => $this->buildProducts($order),
            'totals'    => [
                'total_paid_tax_incl'     => (float) $order->total_paid_tax_incl,
                'total_products_wt'       => (float) $order->total_products_wt,
                'total_shipping_tax_incl' => (float) $order->total_shipping_tax_incl,
                'currency_iso'            => (string) $currency->iso_code,
            ],
            'payment'   => [
                'method' => (string) $order->payment,
                'module' => (string) $order->module,
            ],
            'status'    => [
                'id'      => (int) $orderState->id,
                'name'    => $this->extractLocalized($orderState->name, $idLang),
                'paid'    => (bool) $orderState->paid,
                'shipped' => (bool) $orderState->shipped,
            ],
            'carrier'   => $this->buildCarrier($order, $carrier),
        ];
    }

    private function buildCustomer(Customer $c): array
    {
        if (!Validate::isLoadedObject($c)) {
            return ['id' => 0];
        }

        $ordersCount = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'orders`
             WHERE id_customer = ' . (int) $c->id
        );

        return [
            'id'           => (int) $c->id,
            'firstname'    => (string) $c->firstname,
            'lastname'     => (string) $c->lastname,
            'email'        => (string) $c->email,
            'orders_count' => $ordersCount,
        ];
    }

    private function buildAddress(Address $a): array
    {
        if (!Validate::isLoadedObject($a)) {
            return [];
        }

        $country = new Country((int) $a->id_country);

        return [
            'firstname'    => (string) $a->firstname,
            'lastname'     => (string) $a->lastname,
            'company'      => (string) $a->company,
            'address1'     => (string) $a->address1,
            'address2'     => (string) $a->address2,
            'postcode'     => (string) $a->postcode,
            'city'         => (string) $a->city,
            'country'      => (string) ($country->iso_code ?? ''),
            'phone'        => (string) $a->phone,
            'phone_mobile' => (string) $a->phone_mobile,
        ];
    }

    private function buildProducts(Order $order): array
    {
        $items = $order->getProducts();
        $result = [];
        foreach ((array) $items as $row) {
            $result[] = [
                'id_product'           => (int) ($row['product_id'] ?? ($row['id_product'] ?? 0)),
                'id_product_attribute' => (int) ($row['product_attribute_id'] ?? ($row['id_product_attribute'] ?? 0)),
                'reference'            => (string) ($row['product_reference'] ?? ($row['reference'] ?? '')),
                'ean13'                => (string) ($row['product_ean13'] ?? ''),
                'name'                 => (string) ($row['product_name'] ?? ($row['name'] ?? '')),
                'quantity'             => (int) ($row['product_quantity'] ?? 0),
                'price_unit_tax_incl'  => (float) ($row['unit_price_tax_incl'] ?? 0),
                'price_total_tax_incl' => (float) ($row['total_price_tax_incl'] ?? 0),
                'tax_rate'             => (float) ($row['tax_rate'] ?? 0),
            ];
        }
        return $result;
    }

    private function buildCarrier(Order $order, Carrier $c): array
    {
        return [
            'id'              => (int) $c->id,
            'name'            => (string) ($c->name ?? ''),
            'tracking_number' => (string) ($order->shipping_number ?? ''),
            'weight'          => (float) $order->getTotalWeight(),
        ];
    }

    /**
     * OrderState->name may be an array (multilang) or a string depending on
     * how the object was constructed. Normalize.
     */
    private function extractLocalized($value, int $idLang): string
    {
        if (is_array($value)) {
            if (isset($value[$idLang])) {
                return (string) $value[$idLang];
            }
            $first = reset($value);
            return $first === false ? '' : (string) $first;
        }
        return (string) $value;
    }
}
