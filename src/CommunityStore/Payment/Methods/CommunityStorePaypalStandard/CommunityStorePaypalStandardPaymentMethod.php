<?php
namespace Concrete\Package\CommunityStorePaypalStandard\Src\CommunityStore\Payment\Methods\CommunityStorePaypalStandard;

use Core;
use URL;
use Config;
use Session;
use Log;

use \Concrete\Package\CommunityStore\Src\CommunityStore\Payment\Method as StorePaymentMethod;
use \Concrete\Package\CommunityStore\Src\CommunityStore\Cart\Cart as StoreCart;
use \Concrete\Package\CommunityStore\Src\CommunityStore\Order\Order as StoreOrder;
use \Concrete\Package\CommunityStore\Src\CommunityStore\Customer\Customer as StoreCustomer;
use \Concrete\Package\CommunityStore\Src\CommunityStore\Order\OrderStatus\OrderStatus as StoreOrderStatus;
use Concrete\Core\Multilingual\Page\Section\Section;


class CommunityStorePaypalStandardPaymentMethod extends StorePaymentMethod
{
    public function dashboardForm()
    {
        $this->set('paypalEmail',Config::get('community_store_paypal_standard.paypalEmail'));
        $this->set('paypalTestMode',Config::get('community_store_paypal_standard.paypalTestMode'));
        $this->set('paypalCurrency',Config::get('community_store_paypal_standard.paypalCurrency'));
        $this->set('paypalTransactionDescription',Config::get('community_store_paypal_standard.paypalTransactionDescription'));
        $currencies = array(
            'AUD' => "Australian Dollar",
            'CAD' => "Canadian Dollar",
            'CZK' => "Czech Koruna",
            'DKK' => "Danish Krone",
            'EUR' => "Euro",
            'HKD' => "Hong Kong Dollar",
            'HUF' => "Hungarian Forint",
            'ILS' => "Israeli New Sheqel",
            'JPY' => "Japanese Yen",
            'MXN' => "Mexican Peso",
            'NOK' => "Norwegian Krone",
            'NZD' => "New Zealand Dollar",
            'PHP' => "Philippine Peso",
            'PLN' => "Polish Zloty",
            'GBP' => "Pound Sterling",
            'SGD' => "Singapore Dollar",
            'SEK' => "Swedish Krona",
            'CHF' => "Swiss Franc",
            'TWD' => "Taiwan New Dollar",
            'THB' => "Thai Baht",
            'USD' => "U.S. Dollar"
        );
        $this->set('currencies',$currencies);
        $this->set('form',Core::make("helper/form"));
    }

    public function save(array $data = [])
    {
        Config::save('community_store_paypal_standard.paypalEmail',$data['paypalEmail']);
        Config::save('community_store_paypal_standard.paypalTestMode',$data['paypalTestMode']);
        Config::save('community_store_paypal_standard.paypalCurrency',$data['paypalCurrency']);
        Config::save('community_store_paypal_standard.paypalTransactionDescription',$data['paypalTransactionDescription']);
    }
    public function validate($args,$e)
    {
        $pm = StorePaymentMethod::getByHandle('community_store_paypal_standard');
        if($args['paymentMethodEnabled'][$pm->getID()]==1){
            if($args['paypalEmail']==""){
                $e->add(t("PayPal Email must be set"));
            }
        }
        return $e;

    }
    public function redirectForm()
    {
        $customer = new StoreCustomer();

        $paypalEmail = Config::get('community_store_paypal_standard.paypalEmail');
        $order = StoreOrder::getByID(Session::get('orderID'));
        $this->set('paypalEmail',$paypalEmail);
        $siteName = Config::get('concrete.site');
        $this->set('siteName',Config::get('concrete.site'));
        $this->set('customer', $customer);
        $this->set('total',$order->getTotal());
        $this->set('notifyURL',URL::to('/checkout/paypalresponse'));

        $this->set('orderID',$order->getOrderID());
        $this->set('order',$order);


        $c = \Page::getCurrentPage();
        $al = Section::getBySectionOfSite($c);
        $langpath = '';
        if ($al !== null) {
            $langpath =  $al->getCollectionHandle();
        }

        $this->set('langpath', $langpath);
        $this->set('returnURL',URL::to($langpath . '/checkout/complete'));
        $this->set('cancelReturn',URL::to($langpath . '/checkout'));

        $transactionDescriptionOption = Config::get('community_store_paypal_standard.paypalTransactionDescription');
        $transactionDescription = t('Order from %s', $siteName);

        if ($transactionDescriptionOption == 'products') {
            $transactionDescription = '';
            $products = array();

            $items = $order->getOrderItems();
            if ($items) {
                foreach ($items as $item) {
                    $products[] = $item->getProductName() . ($item->getSKU() ? '(' . $item->getSKU() . ')' : '') .  ($item->getQty() > 1 ? ' x' . $item->getQty() : '') ;
                }

                $transactionDescription = implode(', ' , $products);
                $transactionDescription = substr($transactionDescription, 0, 127);
            }
        }

        $this->set('transactionDescription', $transactionDescription);

        $currencyCode = Config::get('community_store_paypal_standard.paypalCurrency');
        if(!$currencyCode){
            $currencyCode = "USD";
        }
        $this->set('currencyCode',$currencyCode);
    }

    public function submitPayment()
    {

        //nothing to do except return true
        return array('error'=>0, 'transactionReference'=>'');

    }
    public function getAction()
    {
        if(Config::get('community_store_paypal_standard.paypalTestMode')==true){
            return "https://www.sandbox.paypal.com/cgi-bin/webscr";
        } else {
            return "https://www.paypal.com/cgi-bin/webscr";
        }
    }
    public static function validateCompletion()
    {
        // Read POST data
        // reading posted data directly from $_POST causes serialization
        // issues with array data in POST. Reading raw POST data from input stream instead.
        $raw_post_data = file_get_contents('php://input');
        $raw_post_array = explode('&', $raw_post_data);
        $myPost = array();
        foreach ($raw_post_array as $keyval) {
            $keyval = explode ('=', $keyval);
            if (count($keyval) == 2)
                $myPost[$keyval[0]] = urldecode($keyval[1]);
        }
        // read the post from PayPal system and add 'cmd'
        $req = 'cmd=_notify-validate';
        if(function_exists('get_magic_quotes_gpc')) {
            $get_magic_quotes_exists = true;
        }
        foreach ($myPost as $key => $value) {
            if($get_magic_quotes_exists == true && get_magic_quotes_gpc() == 1) {
                $value = urlencode(stripslashes($value));
            } else {
                $value = urlencode($value);
            }
            $req .= "&$key=$value";
        }
        // Post IPN data back to PayPal to validate the IPN data is genuine
        // Without this step anyone can fake IPN data
        if(Config::get('community_store_paypal_standard.paypalTestMode') == true) {
            $paypal_url = "https://ipnpb.sandbox.paypal.com/cgi-bin/webscr";
        } else {
            $paypal_url = "https://ipnpb.paypal.com/cgi-bin/webscr";
        }
        $ch = curl_init($paypal_url);
        if ($ch == FALSE) {
            return FALSE;
        }
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $req);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);

        // CONFIG: Optional proxy configuration
        //curl_setopt($ch, CURLOPT_PROXY, $proxy);
        //curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, 1);
        // Set TCP timeout to 30 seconds
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);

        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'User-Agent: PHP-IPN-Verification-Script',
            'Connection: Close',
        ));

        // CONFIG: Please download 'cacert.pem' from "http://curl.haxx.se/docs/caextract.html" and set the directory path
        // of the certificate as shown below. Ensure the file is readable by the webserver.
        // This is mandatory for some environments.
        //$cert = __DIR__ . "./cacert.pem";
        //curl_setopt($ch, CURLOPT_CAINFO, $cert);

        $res = curl_exec($ch);
        if (curl_errno($ch) != 0) // cURL error
        {
            Log::addError("Can't connect to PayPal to validate IPN message: " . curl_error($ch));
            curl_close($ch);
            exit;
        } else {
            //if we want to log more stuff
            //Log::addEntry("HTTP request of validation request:". curl_getinfo($ch, CURLINFO_HEADER_OUT) ." for IPN payload: $req");
            //Log::addEntry("HTTP response of validation request: $res");
            curl_close($ch);
        }
        // Inspect IPN validation result and act accordingly
        // Split response headers and payload, a better way for strcmp
        $tokens = explode("\r\n\r\n", trim($res));
        $res = trim(end($tokens));
        if (strcmp ($res, "VERIFIED") == 0) {

            if (isset($_POST['invoice']) && $_POST['invoice']) {
                $order = StoreOrder::getByID($_POST['invoice']);
                if ($order) {
                    $order->completeOrder($_POST['txn_id']);
                    $order->updateStatus(StoreOrderStatus::getStartingStatus()->getHandle());
                } else {
                    Log::addWarning("IPN Warning: Order " . $_POST['invoice'] . " not found");
                }
            }

        } elseif (strcmp ($res, "INVALID") == 0) {
            // log for manual investigation
            // Add business logic here which deals with invalid IPN messages
            Log::addError("Invalid IPN: $req");
        }
    }


    public function getPaymentMinimum() {
        return 0.03;
    }


    public function getName()
    {
        return 'Paypal Standard';
    }

    public function isExternal() {
        return true;
    }
}
