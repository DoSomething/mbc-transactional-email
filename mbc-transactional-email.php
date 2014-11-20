<?php

use DoSomething\MBStatTracker\StatHat;
date_default_timezone_set("America/New_York");

/**
 * mbc-transactional-email.php
 *
 * Process entries in the transactionalQueue. Each entry will result in a call
 * to the Mandrill API to send an email address.
 */

// Load up the Composer autoload magic
require_once __DIR__ . '/vendor/autoload.php';

// Load configuration settings common to the Message Broker system
// symlinks in the project directory point to the actual location of the files
require('mb-secure-config.inc');
require('mb-config.inc');

class MBC_TransactionalEmail
{

  /**
   * Message Broker connection to RabbitMQ
   */
  private $messageBroker;

  /**
   * Setting from external services - Mailchimp.
   *
   * @var array
   */
  private $settings;

  /**
   * Setting from external services - Mailchimp.
   *
   * @var array
   */
  private $statHat;

  /**
   * Constructor for MBC_TransactionalEmail
   *
   * @param array $settings
   *   Settings from external services - StatHat
   */
  public function __construct($messageBroker, $settings) {

    $this->messageBroker = $messageBroker;
    $this->settings = $settings;

    // Stathat
    $this->statHat = new StatHat($this->settings['stathat_ez_key'], 'mbc-transactional-email:');
    $this->statHat->setIsProduction(TRUE);
  }

  /*
   * BuildMessage()
   * Assembly of message based on Mandrill API: Send-Template
   * https://mandrillapp.com/api/docs/messages.JSON.html#method=send-template
   *
   * @param object $payload
   *   The email address that the message will be built for.
   */
  private function buildMessage($payload) {

    // Validate payload
    if (empty($payload['email'])) {
      trigger_error('Invalid Payload - Email address in payload is required.', E_USER_WARNING);
      $this->statHat->addStatName('buildMessage: Error - email address blank.');
      return FALSE;
    }

    if (isset($payload['email_tags'])) {
      $tags = $payload['email_tags'];
    }
    else {
      $tags = array($payload['activity']);
    }

    // @todo: Add support for $merge_vars being empty
    $message = array(
      'from_email' => 'no-reply@dosomething.org',
      'from_name' => 'DoSomething.org',
      'to' => array(
        array(
          'email' => $payload['email'],
          'name' => isset($payload['merge_vars']['FNAME']) ? $payload['merge_vars']['FNAME'] : $payload['email'],
        )
      ),
      'tags' => $tags,
    );

    $merge_vars = array();
    if (isset($payload['merge_vars'])) {
      foreach ($payload['merge_vars'] as $varName => $varValue) {
        // Prevent FNAME from being blank
        if ($varName == 'FNAME' && $varValue == '') {
          $varValue = 'Doer';
        }
        $merge_vars[] = array(
          'name' => $varName,
          'content' => $varValue
        );
      }
      $message['merge_vars'][0] = array(
        'rcpt' => $payload['email'],
        'vars' => $merge_vars
      );
    }

    if (isset($payload['email_template'])) {
      $templateName = $payload['email_template'];
    }
    // @todo: remove once email-template is out of code base
    elseif (isset($payload['email-template'])) {
      $templateName = $payload['email-template'];
    }
    else {
      print_r($payload);
      $templateName = 'ds-message-broker-default';
    }

    // example: 'content' => 'Hi *|FIRSTNAME|* *|LASTNAME|*, thanks for signing up.'
    $templateContent = array(
      array(
          'name' => 'main',
          'content' => ''
      ),
    );

    return array($templateName, $templateContent, $message);

  }

  /**
   * $callback = function()
   *   A callback function for basic_consume() that will manage the sending of a
   *   request to Mandrill based on the details in $payload
   *
   * @param string $payload
   *  An JSON array of the details of the message to be sent
   */
  public function consumeTransactionalQueue($payload) {

    // Use the Mandrill service
    $mandrill = new Mandrill();

    // Assemble message details
    // $payloadDetails = unserialize($payload->body);
    $payloadDetails = unserialize($payload->body);
    list($templateName, $templateContent, $message) = $this->buildMessage($payloadDetails);

    // Send message if no errors from building message
    if ($templateName != FALSE) {

      // Send message
      $mandrillResults = $mandrill->messages->sendTemplate($templateName, $templateContent, $message);
      echo '------- mbc-transactional-email Mandrill message sent: ' . $payloadDetails['email'] . ' - ' . date('D M j G:i:s T Y') . ' -------', "\n";

      // Log email address issues returned from Mandrill
      if (isset($mandrillResults[0]['reject_reason']) && $mandrillResults[0]['reject_reason'] != NULL) {
        $this->statHat->addStatName('Mandrill reject_reason: ' . $mandrillResults[0]['reject_reason']);
      }

      // Requeue if Mandrill responds with configuration error
      if (isset($mandrillResults[0]['status']) && $mandrillResults[0]['status'] != 'error') {
        $this->messageBroker->sendAck($payload);
        $this->statHat->addStatName('consumeTransactionalQueue');

        // Log activities
        $this->statHat->clearAddedStatNames();
        $this->statHat->addStatName('activity: ' . $payloadDetails['activity']);

        // Track campaign signups
        if ($payloadDetails['activity'] == 'campaign_signup') {
          $this->statHat->clearAddedStatNames();
          if (isset($payloadDetails['mailchimp_group_name'])) {
            $this->statHat->addStatName('campaign_signup: ' . $payloadDetails['mailchimp_group_name']);
          }
          else {
            $this->statHat->addStatName('campaign_signup: Non staff pic');
          }

        }

      }

    }

    // All addStatName stats will be incremented by one at the end of the callback.
    $this->statHat->reportCount(1);

  }

}

$credentials = array(
  'host' =>  getenv("RABBITMQ_HOST"),
  'port' => getenv("RABBITMQ_PORT"),
  'username' => getenv("RABBITMQ_USERNAME"),
  'password' => getenv("RABBITMQ_PASSWORD"),
  'vhost' => getenv("RABBITMQ_VHOST"),
);

// Set config vars
$config = array(
  'exchange' => array(
    'name' => getenv("MB_TRANSACTIONAL_EXCHANGE"),
    'type' => getenv("MB_TRANSACTIONAL_EXCHANGE_TYPE"),
    'passive' => getenv("MB_TRANSACTIONAL_EXCHANGE_PASSIVE"),
    'durable' => getenv("MB_TRANSACTIONAL_EXCHANGE_DURABLE"),
    'auto_delete' => getenv("MB_TRANSACTIONAL_EXCHANGE_AUTO_DELETE"),
  ),
  'queue' => array(
    'transactional' => array(
      'name' => getenv("MB_TRANSACTIONAL_QUEUE"),
      'passive' => getenv("MB_TRANSACTIONAL_QUEUE_PASSIVE"),
      'durable' => getenv("MB_TRANSACTIONAL_QUEUE_DURABLE"),
      'exclusive' => getenv("MB_TRANSACTIONAL_QUEUE_EXCLUSIVE"),
      'auto_delete' => getenv("MB_TRANSACTIONAL_QUEUE_AUTO_DELETE"),
      'bindingKey' => getenv("MB_TRANSACTIONAL_QUEUE_TOPIC_MB_TRANSACTIONAL_EXCHANGE_PATTERN"),
    ),
  ),
  'consume' => array(
    'consumer_tag' => getenv("MB_TRANSACTIONAL_CONSUME_TAG"),
    'no_local' => getenv("MB_TRANSACTIONAL_CONSUME_NO_LOCAL"),
    'no_ack' => getenv("MB_TRANSACTIONAL_CONSUME_NO_ACK"),
    'exclusive' => getenv("MB_TRANSACTIONAL_CONSUME_EXCLUSIVE"),
    'nowait' => getenv("MB_TRANSACTIONAL_CONSUME_NOWAIT"),
  ),
);
$settings = array(
  'stathat_ez_key' => getenv("STATHAT_EZKEY"),
);


// Kick off
echo '------- mbc-transactional-email START: ' . date('D M j G:i:s T Y') . ' -------', "\n";

$mb = new MessageBroker($credentials, $config);
$mb->consumeMessage(array(new MBC_TransactionalEmail($mb, $settings), 'consumeTransactionalQueue'));

echo '------- mbc-transactional-email END: ' . date('D M j G:i:s T Y') . ' -------', "\n";

