<?php

/**
 * File Paypal.php.
 * Based on marciocamello's repository
 * 
 * @author Denis Rybakov <shinomontaz@gmail.com>
 * @see https://github.com/paypal/rest-api-sdk-php/blob/master/sample/
 * @see https://github.com/marciocamello/yii2-paypal
 */

namespace shinomontaz;

use Yii;
use yii\base\ErrorException;
use yii\helpers\ArrayHelper;
use yii\base\Component;

use PayPal\Api\Address;
use PayPal\Api\Amount;
use PayPal\Api\Payer;
use PayPal\Api\Payment;
use PayPal\Api\Transaction;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Api\Details;
use PayPal\Api\Item;
use PayPal\Api\ItemList;
use PayPal\Api\RedirectUrls;
use PayPal\Rest\ApiContext;
use PayPal\Api\CreditCard;
use PayPal\Api\FundingInstrument;
use PayPal\Api\ExecutePayment;
use PayPal\Api\PaymentExecution;

class Paypal extends Component
{
  const MODE_SANDBOX = 'sandbox';
  const MODE_LIVE    = 'live';

  const LOG_LEVEL_FINE  = 'FINE';
  const LOG_LEVEL_INFO  = 'INFO';
  const LOG_LEVEL_WARN  = 'WARN';
  const LOG_LEVEL_ERROR = 'ERROR';

  public $clientId;
  public $clientSecret;
  public $currency = 'USD';
  public $config = [];

  /** @var ApiContext */
  private $_apiContext = null;

  /**
   * @setConfig 
   * _apiContext in init() method
   */
  public function init()
  {
    $this->setConfig();
  }

  /**
   * @inheritdoc
   */
  private function setConfig()
  {
    $this->_apiContext = new ApiContext(
      new OAuthTokenCredential(
        $this->clientId,
        $this->clientSecret
      )
    );

    $logFileName = \Yii::getAlias('@runtime/logs/paypal.log');
    if( isset($this->config['log.FileName']) &&
        isset($this->config['log.LogEnabled']) &&
        $this->config['log.LogEnabled'] )
    {
      $logFileName = \Yii::getAlias($this->config['log.FileName']);

      if ($logFileName) {
        if (!file_exists($logFileName)) {
          if (!touch($logFileName)) {
            throw new ErrorException( $logFileName . ' for paypal not created!');
          }
        }
      }
      $this->config['log.FileName'] = $logFileName;
    }

    $this->_apiContext->setConfig(
      ArrayHelper::merge([
        'mode'                      => self::MODE_SANDBOX,
        'http.ConnectionTimeOut'    => 30,
        'http.Retry'                => 1,
        'log.LogEnabled'            => YII_DEBUG ? 1 : 0,
        'log.FileName'              => $logFileName,
        'log.LogLevel'              => self::LOG_LEVEL_FINE,
        'validation.level'          => 'log',
        'cache.enabled'             => 'true'
      ],$this->config)
    );

    return $this->_apiContext;
  }
  
  /**
   * Runs a card payment with PayPal
   *
   * @link https://devtools-paypal.com/guide/pay_creditcard/php?interactive=ON&env=sandbox
   * @return PayPal\Api\Payment 
   */
  public function payCard( $cardInfo = [], $sum = 0, $message = '' )
  {
    $card = new CreditCard();
    $card->setType( $cardInfo['cardType'] );
    $card->setNumber( $cardInfo['cardNumber'] );
    $card->setExpireMonth( $cardInfo['expMonth'] );
    $card->setExpireYear($cardInfo['expYear'] );
    $card->setFirstName( $cardInfo['firstName'] );
    $card->setLastName( $cardInfo['lastName'] );

    $fundingInstrument = new FundingInstrument();
    $fundingInstrument->setCreditCard($card);

    $payer = new Payer();
    $payer->setPaymentMethod('credit_card');
    $payer->setFundingInstruments(array($fundingInstrument));

    $amount = new Amount();
    $amount->setCurrency( $this->currency );
    $amount->setTotal( $sum  );

    $transaction = new Transaction();
    $transaction->setAmount($amount);
    if( $message ) {
      $transaction->setDescription( $message );
    }

    $payment = new Payment();
    $payment->setIntent('sale');
    $payment->setPayer($payer);
    $payment->setTransactions(array($transaction));

    return $payment->create($this->_apiContext); // get state from this json
  }

  /**
   * Runs a payment with PayPal
   *
   * @link https://devtools-paypal.com/guide/pay_paypal/php?interactive=ON&env=sandbox
   * @return PayPal\Api\Payment 
   */
  public function payPaypal( $urls = [], $sum = 0, $message = '' )
  {
    $payer = new Payer();
    $payer->setPaymentMethod('paypal');

    $amount = new Amount();
    $amount->setCurrency($this->currency);
    $amount->setTotal($sum);

    $transaction = new Transaction();
    if( $message ) {
      $transaction->setDescription( $message );
    }
    $transaction->setAmount($amount);

    $redirectUrls = new RedirectUrls();
    $return_url = isset( $urls['return_url'] ) ? $urls['return_url'] : 'https://devtools-paypal.com/guide/pay_paypal/php?success=true';
    $cancel_url = isset( $urls['cancel_url'] ) ? $urls['cancel_url'] : 'https://devtools-paypal.com/guide/pay_paypal/php?success=true';

    $redirectUrls->setReturnUrl( $return_url );
    $redirectUrls->setCancelUrl( $cancel_url );

    $payment = new Payment();
    $payment->setIntent('sale');
    $payment->setPayer($payer);
    $payment->setRedirectUrls($redirectUrls);
    $payment->setTransactions( [$transaction] );

    return $payment->create($this->_apiContext); // get approval url from this json
  }
  
  /**
   * Execute previosly approved payment
   *
   * @return PayPal\Api\Payment 
   */
  public function executePayment($paymentId, $payerId) {
    $payment = Payment::get($paymentId, $this->_apiContext);

    $execution = new PaymentExecution();
    $execution->setPayerId($payerId);
    
    return $payment->execute($execution, $this->_apiContext);
  }
  
  /**
   * Set currency
   * 
   * @param string $currency
   */
  public function setCurrency( $currency ) {
    $this->currency = $currency;
  }
  
  /**
   * Returns current currency code
   */  
  public function getCurrency( ) {
    return $this->currency;
  }

}
