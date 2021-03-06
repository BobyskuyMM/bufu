<?php
/**
 * Bufu_Tickets Events Observer
 *
 * @category   Bufu
 * @package    Bufu_Tickets
 * @author     Steffen Mücke <mail@quellkunst.de>
 */
class Bufu_Tickets_Model_Observer
{
    /**
     * Observer for event "catalog_product_prepare_save"
     * Called when saving a product after editing in Backend.
     * Checks for availability of module specific POST data
     * and saves that stuff to DB.
     *
     * Backend observer.
     *
     * @param  Varien_Event_Observer $observer
     * @return Bufu_Tickets_Model_Observer
     */
    public function catalog_product_prepare_save(Varien_Event_Observer $observer)
    {
        $request = $observer->getEvent()->getRequest();
        $product = $observer->getEvent()->getProduct();

        if($myData = $request->getPost('bufu_tickets')) {
            Mage::helper('bufu_tickets')->saveEvents($product, $myData['events']);
        }

        return $this;
    }

    /**
     * Observer for event "sales_order_place_after"
     * Called after an order is placed in the store.
     * Update event quantities for tickets.
     *
     * Frontend observer.
     *
     * @param  Varien_Event_Observer $observer
     * @return Bufu_Tickets_Model_Observer
     */
    public function sales_order_place_after(Varien_Event_Observer $observer)
    {
        /* @var $order Mage_Sales_Model_Order */
        $order = $observer->getEvent()->getOrder();
        /* @var $helper Bufu_Tickets_Helper_Data */
        $helper = Mage::helper("bufu_tickets");
        /* @var $adminHelper Bufu_Tickets_Helper_Admin */
        $adminHelper = Mage::helper("bufu_tickets/admin");

        if (!$helper->isQuantityTrackingEnabled()) {
            return $this;
        }

        foreach ($order->getAllVisibleItems() as $item) {
            /* @var $item Mage_Sales_Model_Order_Item */

            if (!$adminHelper->hasTicketOptions($item)) {
                continue; // go to next order item
            }

            // get product options
            $options = $adminHelper->getTicketOptions($item);
            $eventId = (int) $options[Bufu_Tickets_Helper_Data::OPTION_EVENT_ID];
            $qty     = $item->getQtyOrdered();

            // load event
            /* @var $event Bufu_Tickets_Model_Event|null */
            $event = Mage::getModel("bufu_tickets/event")->load($eventId);
            if (!$event || !$event->getIsTrackQty()) {
                continue; // go to next order item
            }

            // update inventory / quantity tracking
            // both ticket types are tracked on the same quantity quota
            $event->setQtyNormal($event->getQtyNormal() - $qty);
            $event->refreshQuantityTracking();
            $event->save();
        }

        return $this;
    }

    /**
     * Observer for event 'checkout_cart_update_items_after'.
     * Called before items in the cart are updated.
     * Check for tracking quantities being forced when updating quantities in the cart.
     *
     * Frontend observer.
     *
     * @param  Varien_Event_Observer $observer
     * @return Bufu_Tickets_Model_Observer
     */
    public function checkout_cart_update_items_after(Varien_Event_Observer $observer)
    {
        $helper = Mage::helper("bufu_tickets");
        if (!$helper->isQuantityTrackingEnabled()) {
          return $this;
        }

        /* @var $cart Mage_Checkout_Model_Cart */
        $cart = $observer->getEvent()->getCart();

        /* @var $info array */
        $data = $observer->getEvent()->getInfo();

        $hasCorrectedQty = false;
        foreach ($data as $itemId => $params) {
            $quoteItem = $cart->getQuote()->getItemById($itemId);
            if (!$quoteItem) {
                Mage::throwException($this->__('Quote item is not found.'));
            }

            if (!$quoteItem instanceof Bufu_Tickets_Model_Quote_Item) {
                continue;
            }

            $eventData = $helper->getEventFromTicketProduct($quoteItem);
            if (!$eventData instanceof Bufu_Tickets_Model_Event) {
                continue;
            }

            if ($eventData->getIsTrackQty()) {
                $maxQty = intval($eventData->getQtyNormal());
                $qty    = intval($params['qty']);
                if ($qty > $maxQty) {
                    $quoteItem->setQty($maxQty)->save();
                    $hasCorrectedQty = true;
                }
            }
        }

        if ($hasCorrectedQty) {
            $cart->save();
        }
    }
}
