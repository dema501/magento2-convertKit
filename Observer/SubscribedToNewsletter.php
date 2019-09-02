<?php

namespace Liftmode\ConvertKit\Observer;

use Magento\Framework\Event\ObserverInterface;
class SubscribedToNewsletter implements ObserverInterface {
    public const RC_MODULE_ENABLE    = 'newsletter/convertkit/is_enabled';
    public const RC_API_KEY          = 'newsletter/convertkit/api_key';
    public const RC_API_SECRET       = 'newsletter/convertkit/api_secret';
    public const RC_FORM_ID          = 'newsletter/convertkit/form_id';
    public const RC_TAGS             = 'newsletter/convertkit/tags';

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
        $this->_scopeConfig     = $scopeConfig;
        $this->_curl            = $curl;
        $this->customerRegistry = $customerRegistry;
        $this->_logger          = $logger;
        $this->_encryptor       = $encryptor;
    }

    public function execute(\Magento\Framework\Event\Observer $observer) {
        if (!$this->_scopeConfig->getValue(self::RC_MODULE_ENABLE)) {
            return $this;
        }

        $_event        = $observer->getEvent();
        $_subscriber   = $_event->getDataObject();
        $_data         = $_subscriber->getData();
        $_statusChange = $_subscriber->isStatusChanged();

        $api_key    = $this->_scopeConfig->getValue(self::RC_API_KEY);
        $api_secret = $this->_scopeConfig->getValue(self::RC_API_SECRET);

        // Trigger if user is now subscribed and there has been a status change:
        if (!empty($api_key) && $_data['subscriber_status'] == \Magento\Newsletter\Model\Subscriber::STATUS_SUBSCRIBED && $_statusChange == true) {
            $_data['subscriber_firstname'] = "";

            if (!empty($_data['customer_id']) && $_data['customer_id'] > 0) {
                $_customerId = $_data['customer_id'];

                if($_customerId) {
                    $_customer = $this->customerRegistry->retrieve($_customerId);
                    $_data['subscriber_firstname'] = $_customer->getName();
                }
            }

            $this->_logger->debug(json_encode($_data));

            $_params = array(
                'email'        => $_data['subscriber_email'],
                'first_name'   => $_data['subscriber_firstname'],
                'api_key'      => $this->_encryptor->decrypt($api_key),
                'tags'         => $this->_scopeConfig->getValue(self::RC_TAGS),
            );

            $_resp = $this->_curl->post(
                sprintf('https://api.convertkit.com/v3/forms/%s/subscribe', $this->_scopeConfig->getValue(self::RC_FORM_ID)),
                $_params
            );

            $this->_logger->debug(json_encode(array("action" => "subscribe", "params" => $_params, "resp" => $_resp)));
        }
        elseif (!empty($api_secret) && $_data['subscriber_status'] === \Magento\Newsletter\Model\Subscriber::STATUS_UNSUBSCRIBED) {
            $_params = array(
                'email'        => $_data['subscriber_email'],
                'api_secret'   => $this->_encryptor->decrypt($api_secret),
            );

            $this->_curl->put(
                'https://api.convertkit.com/v3/unsubscribe',
                $_params
            );

            $this->_logger->debug(json_encode(array("action" => "unsubscribe", "params" => $_params, "resp" => $_resp)));
        }

        return $this;
    }
}