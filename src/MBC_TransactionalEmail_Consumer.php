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
    $this->logConsumption('email');

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
        // For now, toos the message
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

    echo '-------  mbc-transactional-email - MBC_TransactionalEmail_Consumer->consumeTransactionalQueue() - ' . date('j D M Y G:i:s T') . ' END -------', PHP_EOL . PHP_EOL;
  }
  
  /**
   * Conditions to test before processing the message.
   *
   * @return boolean
   */
  protected function canProcess() {
    
    if (empty($this->message['email'])) {
      echo '- canProcess(), email not set.', PHP_EOL;
      return false;
    }

   if (filter_var($this->message['email'], FILTER_VALIDATE_EMAIL) === false) {
      echo '- canProcess(), failed FILTER_VALIDATE_EMAIL: ' . $this->message['email'], PHP_EOL;
      return false;
    }
    else {
      $this->message['email'] = filter_var($this->message['email'], FILTER_VALIDATE_EMAIL);
    }

    if (empty($this->message['email_template']) && empty($this->message['email-template'])) {
      throw new Exception('Template not defined.');
      return false;
    }

    return true;
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

    if (isset($message['email-template'])) {
      $message['email_template'] = $message['email-template'];
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

    $statName = 'mbc-transactional-email: Mandrill ';
    if (isset($mandrillResults[0]['reject_reason']) && $mandrillResults[0]['reject_reason'] != NULL) {
      throw new Exception(print_r($mandrillResults[0], true));
      $statName .= 'Error: ' . $mandrillResults[0]['reject_reason'];
    }
    elseif (isset($mandrillResults[0]['status']) && $mandrillResults[0]['status'] != 'error') {
      echo '-> mbc-transactional-email Mandrill message sent: ' . $this->request['to'][0]['email'] . ' - ' . date('D M j G:i:s T Y'), PHP_EOL;
      $this->messageBroker->sendAck($this->message['payload']);
      $statName .= 'OK';
    }
    else {
      $statName .= 'No Confirmation';
    }
    $this->statHat->ezCount($statName, 1);
  }

  /**
   * Determine the value of the template name to send with the transactionamail request.
   *
   * @param array $message
   *   Settings of the message from the consumed queue.
   */
  protected function setTemplateName($message) {

    switch ($message['activity']) {

      case "user_register":
      case "user_password":

        // mb-campaign-signup-KR
        if ($message['user_country'] == 'US') {
          $templateName = $message['email_template'];
        }
        elseif ($this->mbToolbox->isDSAffiliate($countryCode)) {
          $templateName = $message['email_template'];
        }
        else {
          $templateName = 'mb-' . str_replace('_', '-', $message['activity']) . '-GL';
        }
        break;

      case "campaign_signup":
      case "campaign_reportback":

        switch (strtolower($message['campaign_language'])) {

          case 'en':
            if (strtoupper($message['user_country']) == 'US') {
              $templateName = 'mb-' . str_replace('_', '-', $message['activity']) . '-US';
            }
            else {
              $templateName = 'mb-' . str_replace('_', '-', $message['activity']) . '-XG';
            }
            break;

          case 'en-global':
            $templateName = 'mb-' . str_replace('_', '-', $message['activity']) . '-XG';
            break;

          case 'es-mx':
            $templateName = 'mb-' . str_replace('_', '-', $message['activity']) . '-MX';
            break;

          case 'pt-br':
            $templateName = 'mb-' . str_replace('_', '-', $message['activity']) . '-BR';
            break;

        }
        break;

      case "vote":

        if (strtoupper($message['user_country']) == 'US') {
          $templateName = 'mb-cgg-vote-US';
        }
        else {
          $templateName = 'mb-cgg-vote-XG';
        }
        break;

       default:
         $templateName = false;

    }

    if ($templateName == false) {
      $statName = 'mbc-transactional-email: Invalid Template';
      $this->statHat->ezCount($statName, 1);
    }
    else {
      $statName = 'mbc-transactional-email: activity: ' . $message['activity'];
      $this->statHat->ezCount($statName, 1);
      $statName = 'mbc-transactional-email: country: ' . $countryCode;
      $this->statHat->ezCount($statName, 1);
      $statName = 'mbc-transactional-email: template: ' . $templateName;
      $this->statHat->ezCount($statName, 1);
    }

    return $templateName;
  }

  /*
   * logConsumption(): Log the status of processing a specific message element.
   *
   * @param string $targetName
   */
  protected function logConsumption($targetName = NULL) {

    if ($targetName != NULL) {
      echo '** Consuming ' . $targetName . ': ' . $this->message[$targetName], PHP_EOL;
      if (isset($this->message['activity'])) {
         echo '- activity: ' . $this->message['activity'], PHP_EOL;
      }
      if (isset($this->message['user_country'])) {
         echo '- User country: ' . $this->message['user_country'], PHP_EOL;
      }
      if (isset($this->message['campaign_language'])) {
         echo '- Campaign language: ' . $this->message['campaign_language'], PHP_EOL;
      }
      echo PHP_EOL;
    } else {
      echo $targetName . ' is not defined.', PHP_EOL;
    }
  }

}
