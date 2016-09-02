<?php

namespace Drupal\sms_mailup\Plugin\SmsGateway;

use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\sms\Plugin\SmsGatewayPluginBase;
use Drupal\sms\Message\SmsMessageInterface;
use Drupal\sms\Message\SmsDeliveryReport;
use Drupal\sms\Message\SmsMessageResult;
use Drupal\sms_mailup\MailUpServiceInterface;
use Drupal\sms\Message\SmsMessageReportStatus;
use Drupal\sms\Message\SmsMessageResultStatus;

/**
 * @SmsGateway(
 *   id = "mailup",
 *   label = @Translation("MailUp"),
 *   outgoing_message_max_recipients = 1,
 * )
 */
class MailUp extends SmsGatewayPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The MailUp service.
   *
   * @var \Drupal\sms_mailup\MailUpServiceInterface
   */
  protected $mailUp;

  /**
   * Constructs a new MailUp instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\sms_mailup\MailUpServiceInterface $mailup
   *   The MailUp service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ClientInterface $http_client, MailUpServiceInterface $mailup) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->httpClient = $http_client;
    $this->mailUp = $mailup;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client'),
      $container->get('sms_mailup.mailup')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $defaults = [
      'account' => [
        'id' => '',
        'username' => '',
        'password' => '',
      ],
      'list' => [
        'id' => '',
        'guid' => '',
        'secret' => '',
      ],
      'campaign_code' => '',
    ];
    return $defaults;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['account'] = [
      '#type' => 'details',
      '#tree' => TRUE,
      '#open' => 'TRUE',
      '#title' => $this->t('Account'),
    ];

    $form['account']['id'] = [
      '#title' => $this->t('Account ID'),
      '#type' => 'textfield',
      '#required' => TRUE,
      '#description' => $this->t('This is usually the same as your username, without the leading "m" character.'),
      '#default_value' => $this->configuration['account']['id'],
    ];

    $form['account']['username'] = [
      '#title' => $this->t('Username'),
      '#type' => 'textfield',
      '#required' => TRUE,
      '#default_value' => $this->configuration['account']['username'],
    ];

    $form['account']['password'] = [
      '#title' => $this->t('Password'),
      '#type' => 'textfield',
      '#required' => TRUE,
      '#default_value' => $this->configuration['account']['password'],
    ];

    $form['list'] = [
      '#type' => 'details',
      '#tree' => TRUE,
      '#open' => 'TRUE',
      '#title' => $this->t('List'),
      '#description' => $this->t('List details can be found by going to <em>Settings » Account settings » Developer\'s Corner » Codes table » Lists</em> in your MailUp console.'),
    ];

    $form['list']['id'] = [
      '#title' => $this->t('List ID'),
      '#type' => 'textfield',
      '#required' => TRUE,
      '#default_value' => $this->configuration['list']['id'],
    ];

    $form['list']['guid'] = [
      '#title' => $this->t('List GUID'),
      '#type' => 'textfield',
      '#required' => TRUE,
      '#default_value' => $this->configuration['list']['guid'],
    ];

    $form['campaign_code'] = [
      '#title' => $this->t('Campaign Code'),
      '#type' => 'textfield',
      '#required' => TRUE,
      '#description' => $this->t('Use \'SMS\' if unsure of the campaign code.'),
      '#default_value' => $this->configuration['campaign_code'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $account_id = $form_state->getValue(['account', 'id']);
    if (!is_numeric($account_id)) {
      $form_state->setError($form['account']['id'], $this->t('Account ID must be a number'));
    }

    $list_id = $form_state->getValue(['list', 'id']);
    if (!is_numeric($list_id)) {
      $form_state->setError($form['list']['id'], $this->t('List ID must be a number'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['account']['id'] = (integer) $form_state->getValue(['account', 'id']);
    $this->configuration['account']['username'] = $form_state->getValue(['account', 'username']);
    $this->configuration['account']['password'] = $form_state->getValue(['account', 'password']);
    $this->configuration['list']['id'] = (integer) $form_state->getValue(['list', 'id']);
    $this->configuration['list']['guid'] = $form_state->getValue(['list', 'guid']);
    $this->configuration['campaign_code'] = $form_state->getValue(['campaign_code']);
  }

  /**
   * {@inheritdoc}
   */
  public function send(SmsMessageInterface $sms_message) {
    // Documentation for Transactional API located at
    // http://help.mailup.com/display/mailupapi/Transactional+SMS+using+APIs
    $recipient = $sms_message->getRecipients()[0];
    $message = $sms_message->getMessage();

    $result = new SmsMessageResult();
    $report = (new SmsDeliveryReport())
      ->setRecipients([$recipient]);

    $account_id = $this->configuration['account']['id'];
    $username = $this->configuration['account']['username'];
    $password = $this->configuration['account']['password'];

    $list_id = $this->configuration['list']['id'];
    $list_guid = $this->configuration['list']['guid'];
    $list_secret = $this->mailUp
      ->getListSecret($username, $password, $list_guid);

    $campaign_code = $this->configuration['campaign_code'];


    $settings = [
      'headers' => [
        'Content-Type' => 'application/json',
      ],
      'json' => [
        'ListID' => $list_id,
        'ListGUID' => $list_guid,
        'ListSecret' => $list_secret,
        'isUnicode' => 0,
        'Recipient' => $recipient,
        'Content' => $message,
        'CampaignCode' => $campaign_code,
      ],
    ];

    $url = 'https://sendsms.mailup.com/api/v2.0/sms/' . $account_id . '/' . $list_id;
    try {
      $response = $this->httpClient
        ->request('post', $url, $settings);
    }
    catch (RequestException $e) {
      $response = $e->getResponse();
    }

    $http_code = $response->getStatusCode();
    $body_encoded = (string) $response->getBody();
    $body = !empty($body_encoded) ? Json::decode($body_encoded) : [];

    $app_code = isset($body['Code']) ? $body['Code'] : -1;
    $description = isset($body['Description']) ? $body['Description'] : '';

    if ($http_code == 200) {
      $report->setStatus(SmsMessageReportStatus::QUEUED);
      $report->setTimeQueued(REQUEST_TIME);
    }
    else if ($app_code == 301) {
      // 301: The message was sent but the statistics are incorrect due to an
      // error.
      $report->setStatus(SmsMessageReportStatus::QUEUED);
      $report->setTimeQueued(REQUEST_TIME);
    }
    else {
      $message = (string) $this->t('Error @code: @description', [
        '@code' => $app_code,
        '@description' => $description,
      ]);
      $result->setStatusMessage($message);
      $report->setStatusMessage($message);

      if ($app_code == 100) {
        // No request found.
        $result->setStatus(SmsMessageResultStatus::ERROR);
      }
      else if ($app_code == 101) {
        // Missing or empty parameters: [a list of parameters].
        $result->setStatus(SmsMessageResultStatus::ERROR);
      }
      else if ($app_code == 102) {
        // ListGUID is not valid for the current account or list.
        $result->setStatus(SmsMessageResultStatus::ERROR);
      }
      else if ($app_code == 103) {
        // ListSecret is not valid for the current account or list.
        $result->setStatus(SmsMessageResultStatus::ERROR);
      }
      else if ($app_code == 104) {
        // SMS sender name is empty for list nr. N
        $result->setStatus(SmsMessageResultStatus::ACCOUNT_ERROR);
      }
      else if ($app_code == 105) {
        // Number or Prefix missing in the recipient.
        $report->setStatus(SmsMessageReportStatus::INVALID_RECIPIENT);
      }
      if ($app_code == 106) {
        // Recipient is invalid.
        $report->setStatus(SmsMessageReportStatus::INVALID_RECIPIENT);
      }
      else if ($app_code == 107) {
        // Content too long.
        $report->setStatus(SmsMessageReportStatus::CONTENT_INVALID);
      }
      else if ($app_code == 201) {
        // listID is not valid for the current account or list.
        $result->setStatus(SmsMessageResultStatus::ERROR);
      }
      else if ($app_code == 202) {
        // ListGUID is not in a correct format.
        $result->setStatus(SmsMessageResultStatus::ERROR);
      }
      else if ($app_code == 203) {
        // ListSecret is not in a correct format.
        $result->setStatus(SmsMessageResultStatus::ERROR);
      }
      else if ($app_code == 204) {
        // Cannot send SMS to USA recipient.
        $report->setStatus(SmsMessageReportStatus::INVALID_RECIPIENT);
      }
      else if ($app_code == 205) {
        // Sending denied: NO CREDITS.
        $result->setStatus(SmsMessageResultStatus::NO_CREDIT);
      }
      else if ($app_code == 206) {
        // SMS number [recipient] is in optout state for list nr.[idList].
        $report->setStatus(SmsMessageReportStatus::INVALID_RECIPIENT);
      }
      else if ($app_code == 207) {
        // Provided SMS sender is not certified and cannot be used to send
        // messages.
        $result->setStatus(SmsMessageResultStatus::ACCOUNT_ERROR);
      }
      else if ($app_code == 250) {
        // Access denied.
        $result->setStatus(SmsMessageResultStatus::ERROR);
      }
      else if ($app_code == 300) {
        // Operation failed: a generic error occur.
        $result->setStatus(SmsMessageResultStatus::ERROR);
      }
      else if ($app_code == 302) {
        // Error delivering message to [recipient].
        $report->setStatus(SmsMessageReportStatus::INVALID_RECIPIENT);
      }
    }

    if ($report->getStatus()) {
      $result->setReports([$report]);
    }

    if (isset($body['Data']['Cost']) && is_float($body['Data']['Cost'])) {
      $result->setCreditsUsed($body['Data']['Cost']);
    }
    if (isset($body['Data']['DeliveryId']) && is_numeric($body['Data']['DeliveryId'])) {
      $report->setMessageId($body['Data']['DeliveryId']);
    }

    return $result;
  }

}
