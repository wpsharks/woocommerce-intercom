<?php
/**
 * Event utils.
 *
 * @author @raamdev
 * @copyright WP Sharks™
 */
declare (strict_types = 1);
namespace WebSharks\WpSharks\WooCommerceIntercom\Classes\Utils;

use WebSharks\WpSharks\WooCommerceIntercom\Classes;
use WebSharks\WpSharks\WooCommerceIntercom\Interfaces;
use WebSharks\WpSharks\WooCommerceIntercom\Traits;
#
use WebSharks\WpSharks\WooCommerceIntercom\Classes\AppFacades as a;
use WebSharks\WpSharks\WooCommerceIntercom\Classes\SCoreFacades as s;
use WebSharks\WpSharks\WooCommerceIntercom\Classes\CoreFacades as c;
#
use WebSharks\WpSharks\Core\Classes as SCoreClasses;
use WebSharks\WpSharks\Core\Interfaces as SCoreInterfaces;
use WebSharks\WpSharks\Core\Traits as SCoreTraits;
#
use WebSharks\Core\WpSharksCore\Classes as CoreClasses;
use WebSharks\Core\WpSharksCore\Classes\Core\Base\Exception;
use WebSharks\Core\WpSharksCore\Interfaces as CoreInterfaces;
use WebSharks\Core\WpSharksCore\Traits as CoreTraits;
#
use function assert as debug;
use function get_defined_vars as vars;
#
use Intercom\IntercomClient;

/**
 * Event utils.
 *
 * @since 000000 Initial release.
 */
class Events extends SCoreClasses\SCore\Base\Core
{
    /**
     * On `woocommerce_order_given` hook.
     *
     * @since 000000 Initial release.
     *
     * @param string|int $order_id Order ID.
     */
    public function onWcOrderGiven($order_id)
    {
        $this->eventCreate((int) $order_id);
    }

    /**
     * On `woocommerce_order_status_changed` hook.
     *
     * @since 000000 Initial release.
     *
     * @param string|int $order_id   Order ID.
     * @param string     $old_status Old status prior to change.
     * @param string     $new_status The new status after this change.
     */
    public function onWcOrderStatusChanged($order_id, string $old_status, string $new_status)
    {
        if (in_array($new_status, ['processing', 'completed'], true)) {
            $this->eventCreate((int) $order_id);
        }
    }

    /**
     * Intercom event create.
     *
     * @since 000000 Initial release.
     *
     * @param string|int $order_id Order ID.
     */
    protected function eventCreate(int $order_id)
    {
        if (!($WC_Order = wc_get_order($order_id))) {
            debug(0, c::issue(vars(), 'Could not acquire order.'));
            return; // Not possible.
        }
        $app_id   = s::getOption('app_id');
        $api_key  = s::getOption('api_key');
        $Intercom = new IntercomClient($app_id, $api_key);

        $user_id = (int) $WC_Order->get_user_id();

        $payment_method = (string) $WC_Order->payment_method; // e.g., `stripe`.
        $total          = (float) $WC_Order->get_total();
        $currency_code  = !empty($payment_method) ? (string) $WC_Order->get_currency() : ''; // e.g., `USD`.

        if ($payment_method === 'stripe') {
            $stripe_customer_id = (string) $WC_Order->stripe_customer_id; // e.g., `cus_xxxx`.
        } else {
            $stripe_customer_id = '';
        }

        $_order_skus = $_order_slugs = []; // Initialize

        foreach ($WC_Order->get_items() ?: [] as $_item_id => $_item) {
            if (!($_WC_Product = s::wcProductByOrderItemId($_item_id, $WC_Order))) {
                continue; // Not a product or not possible.
            }

            $_product['sku'] = (string) $_WC_Product->get_sku();
            if (!$_product['sku'] && $_WC_Product->product_type === 'variation' && $_WC_Product->parent) {
                $_product['sku'] = (string) $_WC_Product->parent->get_sku();
            }
            $_product['slug'] = (string) $_WC_Product->post->post_name;

            if (!empty($_product['sku'])) {
                $_order_skus[] = $_product['sku'];
            } // Collect SKUs for reporting with Order Event below

            if (!empty($_product['slug'])) {
                $_order_slugs[] = $_product['slug'];
            } // Collect Slugs for reporting with Order Event below

            /* Per-Item Event tracking disabled for now.
            $_product['id']    = (int) $_WC_Product->get_id();
            $_product['title'] = (string) $_WC_Product->get_title();

            $_product['qty']   = (int) max(1, (int) ($_item['qty'] ?? 1));
            $_product['total'] = (string) wc_format_decimal($_item['line_total'] ?? 0);

            $_event_metadata = [ // Maximum of five metadata key values.
                'title' => $_product['title'],
                'sku'   => $_product['sku'],
                'slug'  => $_product['slug'],
                'price' => [
                    'currency' => $currency_code,
                    'amount'   => $_product['total'],
                ],
            ];

            if (!empty($stripe_customer_id)) { // Add Stripe Customer Data if available
                $_event_metadata['stripe_customer'] = $stripe_customer_id;
            }

            $Intercom->events->create([
                'created_at' => time(),
                'event_name' => 'purchased-item',
                'user_id'    => $user_id, // Only User ID or Email, not both.
                'metadata'   => $_event_metadata, // See: <https://developers.intercom.io/reference#event-metadata-types>
            ]);*/
        } /* unset($_item_id, $_item, $_product, $_event_metadata); // Housekeeping. */

        // Now create a single `order` Event for the Order itself.

        $_event_metadata = [ // Maximum of five metadata key values; leave room for possible Stripe Customer ID
                             'order_number' => [
                                 'value' => $WC_Order->get_order_number(),
                                 'url'   => admin_url('post.php?post='.$WC_Order->get_order_number().'&action=edit'),
                             ],
                             'subtotal' => [
                                 'currency' => $currency_code,
                                 'amount'   => $total,
                             ],
        ];

        if (!empty($_order_coupons = $WC_Order->get_used_coupons())) {
            $_event_metadata['coupons'] = c::clip(implode(', ', $_order_coupons), 255);
        }
        if (!empty($_order_skus)) {
            $_event_metadata['skus'] = c::clip(implode(', ', $_order_skus), 255);
        } elseif (!empty($_order_slugs)) {
            $_event_metadata['slugs'] = c::clip(implode(', ', $_order_slugs), 255);
        }
        if (!empty($stripe_customer_id)) { // Add Stripe Customer Data if available
            $_event_metadata['stripe_customer'] = $stripe_customer_id;
        }

        $Intercom->events->create([
            'created_at' => time(),
            'event_name' => 'placed-order',
            'user_id'    => $user_id,
            'metadata'   => $_event_metadata, // See: <https://developers.intercom.io/reference#event-metadata-types>
        ]);

        unset($_order_skus, $_order_slugs, $_order_coupons); // Housekeeping.
    }
}