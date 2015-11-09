<?php
/**
 * Send transactional email messages.
 *
 * Process tranasactional email message requests. Interface with email service(Mandrill) to send email
 * messages on demand based on the arrival of messages in the transactionalQueue.
 */

namespace DoSomething\MBC_TransactionalEmail;

use DoSomething\MB_Toolbox\MB_Configuration;
use DoSomething\StatHat\Client as StatHat;
use DoSomething\MB_Toolbox\MB_Toolbox;
use DoSomething\MB_Toolbox\MB_Toolbox_BaseConsumer;
use \Exception;

/**
 * Consume a message in the transactionalQueue to a email message request that sends an email
 * using the Mandrill API.
 *
 * MBC_TransactionalEmail_Consumer class - functionality related to the Message Broker
 * producer mbc-transactional-email application. Process messages in the transactionalQueue to
 * generate transactional email messages using the related email service.
 * @package mbc-transactional-email
 * @link    https://github.com/DoSomething/mbc-transactional-email
 */
class MBC_TransactionalEmail_Consumer extends MB_Toolbox_BaseConsumer
{

  /**
   * Message Broker Toolbox - collection of utility methods used by many of the
   * Message Broker producer and consumer applications.
   * @var object $mbToolbox
   */
  protected $mbToolbox;

  /**
   * Mandrill API
   * @var object $mandrill
   */
  protected $mandrill;

  /**
   * Compiled values for generation of message request to email service
   * @var array $request
   */
  protected $request;

  /**
   * The name of the Madrill template to format the message with.
   * @var array $template
   */
  protected $template;

  /**
   * Extend the base constructor to include loading the Mandrill object.
   */
  public function __construct() {

   parent::__construct();
   $this->mbToolbox = $this->mbConfig->getProperty('mbToolbox');
   $this->mandrill = $this->mbConfig->getProperty('mandrill');
  }

  /**
   *
   * @param string $payload
   *  An JSON array of the details of the message to be sent
   */
  public function consumeTransactionalQueue($payload) {

    echo '-------  mbc-transactional-email - MBC_TransactionalEmail_Consumer->consumeTransactionalQueue() - ' . date('j D M Y G:i:s T') . ' START -------', PHP_EOL;

    parent::consumeQueue($payload);
    echo '** Consuming: ' . $this->message['email'], PHP_EOL;

    if ($this->canProcess()) {

      try {
        $this->setter($this->message);
        $this->process();
      }
      catch(Exception $e) {
        echo 'Error sending transactional email to: ' . $this->message['email'] . '. Error: ' . $e->getMessage() . PHP_EOL;
        $errorDetails = $e->getMessage();
        // @todo: Send error submission to userMailchimpStatusQueue for processing by mb-user-api
        // See issue: https://github.com/DoSomething/mbc-transactional-email/issues/26 and
        // https://github.com/DoSomething/mb-toolbox/issues/54

        $this->messageBroker->sendAck($this->message['payload']);
      }

    }
    else {
      echo '- ' . $this->message['email'] . ' failed canProcess(), removing from queue.', PHP_EOL;
      $this->messageBroker->sendAck($this->message['payload']);
    }

    // @todo: Throttle the number of consumers running. Based on the number of messages
    // waiting to be processed start / stop consumers. Make "reactive"!
    $queueMessages = parent::queueStatus('transactionalQueue');
    echo '- queueMessages ready: ' . $queueMessages['ready'], PHP_EOL;
    echo '- queueMessages unacked: ' . $queueMessages['unacked'], PHP_EOL;

    echo '-------  mbc-transactional-email - MBC_TransactionalEmail_Consumer->consumeTransactionalQueue() - ' . date('j D M Y G:i:s T') . ' END -------', PHP_EOL . PHP_EOL;
  }
  
  /**
   * Conditions to test before processing the message.
   *
   * @return boolean
   */
  protected function canProcess() {
    
    if (!(isset($this->message['email']))) {
      echo '- canProcess(), email not set.', PHP_EOL;
      return FALSE;
    }

   if (filter_var($this->message['email'], FILTER_VALIDATE_EMAIL) === false) {
      echo '- canProcess(), failed FILTER_VALIDATE_EMAIL: ' . $this->message['email'], PHP_EOL;
      return FALSE;
    }
    else {
      $this->message['email'] = filter_var($this->message['email'], FILTER_VALIDATE_EMAIL);
    }

    return TRUE;
  }

  /**
   * Construct values for submission to email service.
   *
   * @param array $message
   *   The message to process based on what was collected from the queue being processed.
   */
  protected function setter($message) {

    $tags = [];
    $tags[] = $message['activity'];

    // Consolidate possible variable names (tags, email_tags) for email message tags
    if (isset($message['tags']) && is_array($message['tags']))  {
      $tags = array_merge($tags, $message['tags']);
    } elseif (isset($message['email_tags']) && is_array($message['email_tags'])) {
      $tags = array_merge($tags, $message['email_tags']);
    }

    // Define user first name
    if (isset($message['merge_vars']['FNAME'])) {
      $firstName = $message['merge_vars']['FNAME'];
    }
    elseif (isset($message['first_name'])) {
      $firstName = $message['first_name'];
      $message['merge_vars']['FNAME'] = $firstName;
    }
    else {
      $firstName = $this->settings->default->first_name;
      $message['merge_vars']['FNAME'] = $firstName;
    }

    $this->request = array(
      'from_email' => $this->settings['email']['from'],
      'from_name' => $this->settings['email']['name'],
      'to' => array(
        array(
          'email' => $message['email'],
          'name' => $firstName,
        )
      ),
      'tags' => $tags,
    );

    $merge_vars = array();
    if (isset($message['merge_vars'])) {
      foreach ($message['merge_vars'] as $varName => $varValue) {
        $merge_vars[] = array(
          'name' => $varName,
          'content' => $varValue
        );
      }
      $this->request['merge_vars'][0] = array(
        'rcpt' => $message['email'],
        'vars' => $merge_vars
      );
    }

    $this->template = $this->setTemplateName($message);
  }

  /**
   * process(): Send composed settings to Mandrill to trigger transactional email message being sent.
   */
  protected function process() {

    // example: 'content' => 'Hi *|FIRSTNAME|* *|LASTNAME|*, thanks for signing up.'
    // Needs to be set due to "funkiness" in MailChimp API that requires a value
    // regardless of the use of a template.
    $templateContent = array(
      array(
          'name' => 'main',
          'content' => ''
      ),
    );

    $mandrillResults = $this->mandrill->messages->sendTemplate($this->template, $templateContent, $this->request);

    $statName = 'mbc-transactional-email: Mandrill';
    if (isset($mandrillResults[0]['reject_reason']) && $mandrillResults[0]['reject_reason'] != NULL) {
      throw new Exception(print_r($mandrillResults[0], TRUE));
      $statName = 'mbc-transactional-email: Mandrill Error: ' . $mandrillResults[0]['reject_reason]'];
    }
    elseif (isset($mandrillResults[0]['status']) && $mandrillResults[0]['status'] != 'error') {
      echo '-> mbc-transactional-email Mandrill message sent: ' . $this->request['to'][0]['email'] . ' - ' . date('D M j G:i:s T Y'), PHP_EOL;
      $this->messageBroker->sendAck($this->message['payload']);
      $statName = 'mbc-transactional-email: Mandrill OK';
    }
    $this->statHat->ezCount($statName, 1);
  }

  /**
   * Determine the setting for the template name to send with the transactionamail request.
   *
   * @param array $message
   *   Settings of the message from the consumed queue.
   */
  protected function setTemplateName($message) {

    if (isset($message['email_template'])) {
      $template = $message['email_template'];
    }
    elseif (isset($message['email-template'])) {
      $template = $message['email-template'];
    }
    else {
      throw new Exception('Template not defined : ' . print_r($message, TRUE));
      $template = FALSE;
    }

    // Don't apply country code logic to transactional requests that define source
    // A source value suggests user import or other app that doesn't want the
    // default template name generation.
    if (!(isset($message['source']))) {

      // mb-campaign-signup-KR
      $templateBits = explode('-', $template);
      $countryCode = $templateBits[count($templateBits) - 1];
      if ($this->mbToolbox->isDSAffiliate($countryCode)) {
        $templateName = $template;
      }
      else {
        $templateName = 'mb-' . str_replace('_', '-', $message['activity']) . '-US';
      }

      echo '- activity: ' . $message['activity'], PHP_EOL;
      $statName = 'mbc-transactional-email: activity: ' . $message['activity'];
      $this->statHat->ezCount($statName, 1);
      echo '- countryCode: ' . $countryCode, PHP_EOL;
      $statName = 'mbc-transactional-email: country: ' . $countryCode;
      $this->statHat->ezCount($statName, 1);

    }
    else {
      $templateName = $template;
    }

    echo '- setTemplateName: ' . $templateName, PHP_EOL;
    $statName = 'mbc-transactional-email: template: ' . $templateName;
    $this->statHat->ezCount($statName, 1);

    return $templateName;
  }

}
