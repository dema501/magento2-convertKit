<?php

namespace Liftmode\ConvertKit\Observer;

use Magento\Framework\Event\ObserverInterface;
class SubscribedToNewsletter implements ObserverInterface {
    const RC_MODULE_ENABLE    = 'newsletter/convertkit/enable';
    const RC_API_KEY          = 'newsletter/convertkit/api_key';
    const RC_API_SECRET       = 'newsletter/convertkit/api_secret';
    const RC_FORM_ID          = 'newsletter/convertkit/form_id';
    const RC_TAGS             = 'newsletter/convertkit/tags';


    protected $_scopeConfig;
    protected $_customerRegistry;
    protected $_curl;
    protected $_logger;


    /**
     * @param \Ecomail\Ecomail\Helper\Data             $helper
     * @param \Magento\Customer\Model\CustomerRegistry $customerRegistry
     */
    public function __construct(
        \Magento\Framework\HTTP\Client\Curl $curl,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Customer\Model\CustomerRegistry $customerRegistry,
        \Psr\Log\LoggerInterface $logger
    ) {
        $this->_scopeConfig     = $scopeConfig;
        $this->_curl            = $curl;
        $this->customerRegistry = $customerRegistry;
        $this->_logger          = $logger;
    }

    public function execute(\Magento\Framework\Event\Observer $observer) {
        if (!$this->_scopeConfig->getValue(self::RC_MODULE_ENABLE)) {
            return $this;
        }

        $_event        = $observer->getEvent();
        $_subscriber   = $_event->getDataObject();
        $_data         = $_subscriber->getData();
        $_statusChange = $_subscriber->isStatusChanged();

        // Trigger if user is now subscribed and there has been a status change:
        if ($_data['subscriber_status'] == \Magento\Newsletter\Model\Subscriber::STATUS_SUBSCRIBED && $_statusChange == true) {

            if ($this->_scopeConfig->getValue(self::RC_API_KEY)) {
                if (empty($_data['subscriber_firstname'])) {
                    $_customerId = $_subscriber['customer_id'];

                    if($_customerId) {
                        $_customer = $this->customerRegistry->retrieve($_customerId);
                        $_data['subscriber_firstname'] = $_customer->getName();
                    }
                }

                $_params = array(
                    'email'        => $_data['subscriber_email'],
                    'first_name'   => $_data['subscriber_firstname'],
                    'api_key'      => $this->_scopeConfig->getValue(self::RC_API_KEY),
                    'tags'         => $this->_scopeConfig->getValue(self::RC_TAGS),
                );

                $_resp = $this->_curl->post(
                    sprintf('https://api.convertkit.com/v3/forms/%s/subscribe', $this->_scopeConfig->getValue(self::RC_FORM_ID)),
                    $_params
                );

                $this->_logger->debug(json_encode(array("action" => "subscribe", "params" => $_params, "resp" => $_resp)));
            }
        }
        elseif ($_data['subscriber_status'] === \Magento\Newsletter\Model\Subscriber::STATUS_UNSUBSCRIBED) {
            $_params = array(
                'email'        => $_data['subscriber_email'],
                'api_secret'   => $this->_scopeConfig->getValue(self::RC_API_KEY),
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