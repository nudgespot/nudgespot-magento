<?php

class Nudgespot_Connect_Model_Observer extends Mage_Core_Model_Abstract {

    public $token;

    public function __construct($token_string) {
        $this->token = Mage::getStoreConfig('nudgespot/settings/nudgespot_api_token');
    }

    function host() {
        $api_secret = Mage::getStoreConfig('nudgespot/settings/nudgespot_api_secret');
        // return 'https://api:' . $api_secret . '@api.nudgespot.com';
        return 'http://api:' . $api_secret . '@staging.nudgespot.com';
    }

    function getCategoryName($productId) {
        $product = Mage::getModel('catalog/product')->load($productId);
        $category_name = "";
        $cats = $product->getCategoryIds();
        $cnt = 0;
        foreach ($cats as $category_id) {
            $_cat = Mage::getModel('catalog/category')->load($category_id);
            $cnt++;
            if ($cnt == count($cats))
                $category_name.=$_cat->getName();
            else
                $category_name.=$_cat->getName() . ",";
        }
        return $category_name;
    }

    function getItem($cart, &$item) {
        $productId = $item->getProduct()->getId();
        $name = addslashes($item->getProduct()->getName());
        $category = addslashes($this->getCategoryName($productId));
        $qty = $cart->getItemQty($item->getProduct()->getId());
        $price = $item->getProduct()->getPrice();
        return array('id' => $productId, 'name' => $name, 'category' => $category, 'price' => $price, 'quantity' => $qty);
    }

    public function trackAddToCart($observer) {
        Mage::log('Nudgespot:: Add to cart');
        $event = $observer->getEvent();
        $cart = Mage::getModel('checkout/cart')->getQuote();
        $items = $cart->getAllItems();
        $cart = Mage::getSingleton('checkout/cart');
        $cart_items = array();
        foreach ($items as &$item) {
            array_push($cart_items, $this->getItem($cart, $item));
        }
        $this->track('update_cart', array('items' => $cart_items,
            'cart_count' => $cart->getItemsCount(),
            'cart_total' => $cart->getQuote()->getGrandTotal()), null);
    }

    public function trackReview($observer) {
        $event = $observer->getEvent();
        $action = $event->getControllerAction();
        $post_data = $action->getRequest()->getPost();
        if (isset($post_data['detail'])) {
            $this->track('review_product', array('nickname' => $post_data['nickname'],
                'title' => $post_data['title'],
                'distinct_id' => $this->getCustomerIdentity()), null);
        }
    }

    public function getCustomerIdentity() {
        if (Mage::getSingleton('customer/session')->isLoggedIn()) {
            $c = Mage::getSingleton('customer/session')->getCustomer();
            $customer = Mage::getModel('customer/customer')->load($c->getId());
            $person = array();
            $person = $this->getCustomerTrackInfo($customer);
            return $customer->getEmail();
        } else {
            return null;
        }
    }

    public function trackOrder($observer) {
        $order = $observer->getEvent()->getOrder();
        $quote = $order->getQuote();
        $guest = $order->getCustomerIsGuest();
        if ($guest == 1) {
            $customer_email = $order->getCustomerEmail();
        } else {
            $customer_id = $order->getCustomerId();
            $customer = Mage::getModel('customer/customer')->load($customer_id);
            $customer_email = $customer->getEmail();
        }
        $customer_id = $order->getCustomerId();
        $customer = Mage::getModel('customer/customer')->load($customer_id);
        $customer_email = $customer->getEmail();
        $items = $order->getItemsCollection();
        $order_items = array();
        Mage::log("Purchase event");
        foreach ($order->getItemsCollection() as $item) {
            $product_id = $item->getProductId();
            $product = Mage::getModel('catalog/product')
                    ->setStoreId(Mage::app()->getStore()->getId())
                    ->load($product_id);
            $productId = $item->getProduct()->getId();
            $name = addslashes($item->getProduct()->getName());
            $category = addslashes($this->getCategoryName($productId));
            $price = $item->getProduct()->getPrice();
            $order_items[] = array('id' => $productId, 'name' => $name, 'category' => $category, 'price' => $price);
        }
        $order_date = $quote->getUpdatedAt();
        $order_date = str_replace(' ', 'T', $order_date);
        $revenue = $quote->getBaseGrandTotal();
        $this->track('purchase', array('items' => $order_items,
            'order_date' => $order_date,
            'order_total' => $revenue), $customer_email);
    }

    public function trackCustomerSave($observer) {
        $customer = $observer->getCustomer();
        if ($customer->isObjectNew() && !$customer->getCustomerAlreadyProcessed()) {
            $customer->setCustomerAlreadyProcessed(true);
            $person = array();
            $person = $this->getCustomerTrackInfo($customer);
        }
    }

    public function getCustomerTrackInfo($customer) {
        $person = array();
        $person['email'] = $customer->getEmail();
        $person['first_name'] = $customer->getFirstname();
        $person['last_name'] = $customer->getLastname();
        $person['created'] = $customer->getCreatedAt();
        $person['distinct_id'] = $customer->getEmail();
        return $person;
    }

    public function trackCustomerFromCheckout($observer) {
        $order = $observer->getEvent()->getOrder();
        $quoteId = $order->getQuoteId();
        $quote = Mage::getModel('sales/quote')->load($quoteId);
        $method = $quote->getCheckoutMethod(true);
        if ($method == 'register') {
            $customer_id = $order->getCustomerId();
            $customer = Mage::getModel('customer/customer')->load($customer_id);
            $customer_email = $customer->getEmail();
            $this->track('register', array(), $customer_email);
        }
    }

    public function trackCustomerRegisterSuccess($observer) {
        $customer = $observer->getCustomer();
        $customer_email = $customer->getEmail();
        $this->track('register', array(), $customer_email);
    }

    public function trackCoupon($observer) {
        $action = $observer->getEvent()->getControllerAction();
        $coupon_code = trim($action->getRequest()->getParam('coupon_code'));
        if (isset($coupon_code) && !empty($coupon_code)) {
            $this->track('use_coupon', array('code' => $coupon_code, 'distinct_id' => $this->getCustomerIdentity()), null);
        }
        return $this;
    }

    public function alias($identifier) {
        $params['event'] = '$create_alias';
        $visitorData = Mage::getSingleton('core/session')->getVisitorData();
        $params['properties']['distinct_id'] = $visitorData['visitor_id'];
        $params['properties']['$initial_referrer'] = '$direct';
        $params['properties']['$initial_referring_domain'] = '$direct';
        $params['properties']['alias'] = $identifier;
        $params['properties']['token'] = $this->token;
        $url = $this->host() . 'track/?data=';
        $json = json_encode($params);
        $this->apiCall($json);
        // exec("curl '" . $url . "-X POST -H Content-Type: application/json -d '" . json_encode($params) . "' >/dev/null 2>&1 &");
    }

    public function track($event, $properties = array(), $customer = null) {
        if (empty($customer)) {
            $customer = $this->getCustomerIdentity();
            if (empty($customer)) {
                Mage::log("Event '" . $event . "' cannot be tracked, user info not available");
                return;
            }
        }
        Mage::log("Tracking '" . $event . "' by " . $customer);
        $user = array();
        $user['email'] = $customer;
        $params = array(
            'event' => $event,
            'properties' => $properties,
            'user' => $user
        );

        $params['properties']['token'] = $this->token;
        $url = $this->host() . '/activities';
        Mage::log("curl '" . $url . "' -X POST -H 'Content-Type: application/json' -H 'Accept: application/json' -d '" . json_encode(array('activity' => $params)) . "' >/dev/null 2>&1 &");
        // exec("curl '" . $url . "' -X POST -H 'Content-Type: application/json' -H 'Accept: application/json' -d '" . json_encode(array('activity' => $params)) . "' >/dev/null 2>&1 &");
        $json = json_encode(array('activity' => $params));
        $this->apiCall($json);
    }

    public function apiCall($json) {
        $url = $this->host() . '/activities';
        $header[] = "Content-Type: application/json";
        $header[] = "Accept: application/json";
        $header[] = "Content-Length: " . strlen($json);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_exec($ch);
        curl_close($ch);
    }
}
