<?php

namespace Drupal\sms_mailup;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Drupal\Component\Serialization\Json;

/**
 * The MailUp service.
 */
class MailUpService implements MailUpServiceInterface {

  /**
   * The Drupal HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Constructs a new MailUpService object.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The Guzzle HTTP client.
   */
  function __construct(ClientInterface $http_client) {
    $this->httpClient = $http_client;
  }

  /**
   * Get the secret for a list, store it in local state since plugins can't
   * look into their entity, or update configuration dynamically.
   */
  function getListSecret($username, $password, $list_guid) {
    $id = 'sms_mailup.list.secrets.' . $list_guid;
    if ($secret = \Drupal::state()->get($id)) {
      return $secret;
    }

    // @todo: GET, then POST if non-exist.

    $settings = [
      'auth' => [$username, $password],
      'headers' => [
        'Content-Type' => 'application/json',
      ],
      'json' => [
        'ListGUID' => $list_guid,
      ],
    ];

    $url = 'https://sendsms.mailup.com/api/v2.0/lists/1/listsecret';
    try {
      $response = $this->httpClient
        ->request('post', $url, $settings);
    }
    catch (RequestException $e) {
      return NULL;
    }

    if ($response->getStatusCode() == 200) {
      // {"Data":{"ListSecret":"$the-secret"},"Code":"0","Description":"","State":"DONE"}
      $body_encoded = (string) $response->getBody();
      $body = !empty($body_encoded) ? Json::decode($body_encoded) : [];
      if (!empty($body['Data']['ListSecret'])) {
        $secret = $body['Data']['ListSecret'];
        \Drupal::state()->set($id, $secret);
        return $secret;
      }
    }

    return NULL;
  }

}
