<?php

namespace Liftmode\ConvertKit\Observer;

use Magento\Framework\Event\ObserverInterface;

class SubscribedToNewsletter implements ObserverInterface {
    public const RC_MODULE_ENABLED    = 'newsletter/convertkit/is_enabled';
    public const RC_API_KEY          = 'newsletter/convertkit/api_key';
    public const RC_API_SECRET       = 'newsletter/convertkit/api_secret';
    public const RC_FORM_ID          = 'newsletter/convertkit/form_id';
    public const RC_TAGS             = 'newsletter/convertkit/tags';
    public const RC_MODULE_DEBUG_ENABLED    = 'newsletter/convertkit/is_debug_enabled';

    private   $_scopeConfig;
    private   $_customerRegistry;
    private   $_curl;
    private   $_logger;
    private   $_encryptor;

    /**
     * @param \Ecomail\Ecomail\Helper\Data             $helper
     * @param \Magento\Customer\Model\CustomerRegistry $customerRegistry
     */
    public function __construct(
        \Magento\Framework\HTTP\Client\Curl $curl,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Customer\Model\CustomerRegistry $customerRegistry,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\Encryption\EncryptorInterface $encryptor
    ) {
        $this->_scopeConfig      = $scopeConfig;
        $this->_curl             = $curl;
        $this->_customerRegistry = $customerRegistry;
        $this->_logger           = $logger;
        $this->_encryptor        = $encryptor;
    }

    public function execute(\Magento\Framework\Event\Observer $observer) {
        if (!$this->_scopeConfig->getValue(self::RC_MODULE_ENABLED)) {
            return $this;
        }

        $_event        = $observer->getEvent();
        $_subscriber   = $_event->getDataObject();
        $_data         = $_subscriber->getData();
        $_statusChange = $_subscriber->isStatusChanged();

        $api_key    = $this->_scopeConfig->getValue(self::RC_API_KEY);

        // Trigger if user is now subscribed and there has been a status change:
        if (!empty($api_key) && $_data['subscriber_status'] == \Magento\Newsletter\Model\Subscriber::STATUS_SUBSCRIBED && $_statusChange == true) {
            if (!array_key_exists('subscriber_firstname', $_data) || empty($_data['subscriber_firstname'])) {
                $_data['subscriber_firstname'] = "";

                if (!empty($_data['customer_id']) && $_data['customer_id'] > 0) {
                    $_customerId = $_data['customer_id'];

                    if($_customerId) {
                        $_customer = $this->_customerRegistry->retrieve($_customerId);
                        $_data['subscriber_firstname'] = $_customer->getFirstName();
                    }
                }
            }

            $_params = array(
                'email'        => $_data['subscriber_email'],
                'first_name'   => $_data['subscriber_firstname'],
                'api_key'      => $this->_encryptor->decrypt($api_key),
                'tags'         => $this->_scopeConfig->getValue(self::RC_TAGS),
            );

            //\Magento\Framework\HTTP\Client\Curl do work for me
            $this->_curl->post(
                sprintf('https://api.convertkit.com/v3/forms/%s/subscribe', $this->_scopeConfig->getValue(self::RC_FORM_ID)),
                $_params
            );

            if ($this->_scopeConfig->getValue(self::RC_MODULE_DEBUG_ENABLED)) {
                $_resp = $this->_curl->getBody();
                $this->_logger->debug(json_encode(array("action" => "subscribe", "params" => $_params, "resp" => $_resp)));
            }

            return $this;
        }

        $api_secret = $this->_scopeConfig->getValue(self::RC_API_SECRET);
        if (!empty($api_secret) && $_data['subscriber_status'] === \Magento\Newsletter\Model\Subscriber::STATUS_UNSUBSCRIBED) {
            $_params = array(
                'email'        => $_data['subscriber_email'],
                'api_secret'   => $this->_encryptor->decrypt($api_secret),
            );

            $_paramsJson = json_encode($_params);

            //Custom options to make put request
            $this->_curl->setOption(CURLOPT_HTTPGET, 0);
            $this->_curl->setOption(CURLOPT_CUSTOMREQUEST, "PUT");
            $this->_curl->setOption(CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Content-Length: ' . strlen($_paramsJson)));
            $this->_curl->setOption(CURLOPT_POSTFIELDS, $_paramsJson);

            // Initiate sending, custom options will convert GET to PUT inside
            $this->_curl->get(
                'https://api.convertkit.com/v3/unsubscribe'
            );

            if ($this->_scopeConfig->getValue(self::RC_MODULE_DEBUG_ENABLED)) {
                $_resp = $this->_curl->getBody();
                $this->_logger->debug(json_encode(array("action" => "unsubscribe", "params" => $_params, "resp" => $_resp)));
            }

            return $this;
        }

        return $this;
    }
}