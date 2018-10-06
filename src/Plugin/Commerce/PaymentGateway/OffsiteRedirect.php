<?php

namespace Drupal\commerce_idpay\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Exception\InvalidResponseException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Client;


/**
 * Provides the Off-site Redirect payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "idpay_offsite_redirect",
 *   label = "IDPay (Off-site redirect)",
 *   display_label = "IDPay",
 *   forms = {
 *     "offsite-payment" =
 *   "Drupal\commerce_idpay\PluginForm\OffsiteRedirect\PaymentOffsiteForm",
 *   },
 *   payment_method_types = {"credit_card"}
 * )
 */
class OffsiteRedirect extends OffsitePaymentGatewayBase {

  /**
   * The payment storage.
   *
   * @var \Drupal\commerce_payment\PaymentStorageInterface
   */
  protected $paymentStorage;

  /**
   * The http client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;


  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, TimeInterface $time, Client $http_client) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);
    $this->paymentStorage = $entity_type_manager->getStorage('commerce_payment');
    $this->httpClient = $http_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_payment_type'),
      $container->get('plugin.manager.commerce_payment_method_type'),
      $container->get('datetime.time'),
      $container->get('http_client')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Key'),
      '#default_value' => $this->configuration['api_key'],
      '#description' => $this->t('You can obtain an API Key from https://idpay.ir/dashboard/web-services'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['api_key'] = $values['api_key'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    parent::onReturn($order, $request);

    $status = $request->request->get('status');
    $track_id = $request->request->get('track_id');
    $remote_id = $request->request->get('id');
    $order_id = $request->request->get('order_id');
    $amount = $request->request->get('amount');
    $date = $request->request->get('date');

    if ($order->id() != $order_id) {
      throw new PaymentGatewayException('Abuse of transaction callback.');
    }

    $payment = $this->loadPayment($remote_id, $order_id);

    if ($payment) {
      $url = 'https://api.idpay.ir/v1/payment/inquiry';
      $params = [
        'id' => $payment->getRemoteId(),
        'order_id' => $payment->getOrderId(),
      ];

      $headers = [
        'Content-Type' => 'application/json',
        'X-API-KEY' => $this->configuration['api_key'],
        'X-SANDBOX' => ($this->configuration['mode'] == 'test' ? 'true' : 'false'),
      ];

      try {
        $response = $this->httpClient->request('POST', $url, [
          'headers' => $headers,
          'body' => json_encode($params),
        ]);

        $response_contents = $response->getBody()->getContents();
        $response_contents = json_decode($response_contents);
        if ($response_contents->status == 100) {
          $payment->setState('completed');
          $payment->setRemoteState('track_id: ' . $response_contents->track_id . ' / status: ' . $response_contents->status);
          $payment->save();
        }
        else {
          $payment->setRemoteState('track_id: ' . $response_contents->track_id . ' / status: ' . $response_contents->status);
          $payment->save();
          throw new PaymentGatewayException($this->t("commerce_idpay: Payment failed with status code: %code", [
            '%code' => $response_contents->status,
          ]));
        }

      } catch (RequestException $e) {
        throw new InvalidResponseException('commerce_idpay: ' . $e->getMessage());
      }
    }
    else {
      throw new PaymentGatewayException($this->t('commerce_idpay: cannot find any payment with remote id: @remote_id and order id: @order id, so that we can update it to completed.', [
        '@remote_id' => $remote_id,
        '@order_id' => $order_id,
      ]));
    }

  }

  /**
   * Helper function for loading a commerce payment
   */
  private function loadPayment($remote_id, $order_id) {
    $payments = $this->paymentStorage->loadByProperties([
      'remote_id' => $remote_id,
      'order_id' => $order_id,
      'state' => 'authorization',
    ]);
    if (count($payments) == 1) {
      $payment_id = array_keys($payments)[0];
      /** @var \Drupal\commerce_payment\Entity\Payment $payment */
      $payment = $payments[$payment_id];

      return $payment;
    }

  }

}
