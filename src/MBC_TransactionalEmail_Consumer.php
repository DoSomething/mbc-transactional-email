<?php
/**
 * Send transactional email messages.
 *
 * Process tranasactional email message requests. Interface with email service(Mandrill) to send email
 * messages on demand based on the arrival of messages in the transactionalQueue.
 *
 * @package mbc-transactional-email
 * @link    https://github.com/DoSomething/mbc-transactional-email
 */

namespace DoSomething\MBC_TransactionalEmail;

use DoSomething\MB_Toolbox\MB_Configuration;
use DoSomething\StatHat\Client as StatHat;
use DoSomething\MB_Toolbox\MB_Toolbox;
use DoSomething\MB_Toolbox\MB_Toolbox_BaseConsumer;
use \Exception;

/**
 * Convert a message in the transactionalQueue to a email message request sent to the Mandrill API.
 *
 * MBC_TransactionalEmail_Consumer class - functionality related to the Message Broker
 * producer mbc-transactional-email application. Process messages in the transactionalQueue to
 * generate transactional email messages using the related email service.
 */
class MBC_TransactionalEmail_Consumer extends MB_Toolbox_BaseConsumer
{

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
   * $callback = function()
   *   A callback function for basic_consume() that will manage the sending of a
   *   request to Mandrill based on the details in $payload
   *
   * @param string $payload
   *  An JSON array of the details of the message to be sent
   */
  public function consumeTransactionalQueue($payload) {

    echo '-------  mbc-transactional-email - MBC_TransactionalEmail_Consumer->consumeTransactionalQueue() START -------', PHP_EOL;

    parent::consumeQueue($payload);
    echo PHP_EOL . PHP_EOL;
    echo '** Consuming: ' . $this->message['email'], PHP_EOL;

    if ($this->canProcess()) {

      try {

        $this->setter();
        $this->process();
      }
      catch(Exception $e) {
        echo 'Error sending transactional email to: ' . $this->message['email'] . '. Error: ' . $e->getMessage();
      }

    }

    // @todo: Throttle the number of consumers running. Based on the number of messages
    // waiting to be processed start / stop consumers. Make "reactive"!
    $queueMessages = parent::queueStatus('digestUserQueue');
    echo '- queueMessages ready: ' . $queueMessages['ready'], PHP_EOL;
    echo '- queueMessages unacked: ' . $queueMessages['unacked'], PHP_EOL;

    $this->messageBroker->sendAck($this->message['payload']);
    echo '- Ack sent: OK', PHP_EOL . PHP_EOL;

    echo '-------  mbc-transactional-email - MBC_TransactionalEmail_Consumer->consumeTransactionalQueue() END -------', PHP_EOL;
  }
  
  /**
   * Conditions to test before processing the message.
   *
   * @return boolean
   */
  private function canProcess() {
    
    if (isset($this->message['email'])) {
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
   */
  private function setter() {

    $tags = [];
    $tags[] = $this->message['activity'];

    // Consolidate possible variable names (tags, email_tags) for email message tags
    if (isset($this->message['tags']) && is_array($this->message['tags']))  {
      $tags = array_merge($tags, $this->message['tags']);
    } elseif (isset($this->message['email_tags']) && is_array($this->message['email_tags'])) {
      $tags = array_merge($tags, $this->message['email_tags']);
    }

    // Define user first name
    if (isset($this->message['merge_vars']['FNAME'])) {
      $firstName = $this->message['merge_vars']['FNAME'];
    }
    elseif (isset($this->message['first_name'])) {
      $firstName = $this->message['first_name'];
      $this->message['merge_vars']['FNAME'] = $firstName;
    }
    else {
      $firstName = $this->settings->default->first_name;
      $this->message['merge_vars']['FNAME'] = $firstName;
    }

    $this->request = array(
      'from_email' => $this->settings->email->from,
      'from_name' => $this->settings->email->name,
      'to' => array(
        array(
          'email' => $this->message['email'],
          'name' => $firstName,
        )
      ),
      'tags' => $tags,
    );

    $merge_vars = array();
    if (isset($this->message['merge_vars'])) {
      foreach ($this->message['merge_vars'] as $varName => $varValue) {
        $merge_vars[] = array(
          'name' => $varName,
          'content' => $varValue
        );
      }
      $this->request['merge_vars'][0] = array(
        'rcpt' => $this->message['email'],
        'vars' => $merge_vars
      );
    }

    if (isset($this->message['email_template'])) {
      $this->template = $this->message['email_template'];
    }
    elseif (isset($this->message['email-template'])) {
      $this->template = $this->message['email-template'];
    }
    else {
      throw new Exception('Template not defined : ' . print_r($payload, TRUE));
      $templateName = FALSE;
    }

  }

  /**
   * process(): Send composed settings to Mandrill to trigger transactional email message being sent.
   */
  private function process() {

    // example: 'content' => 'Hi *|FIRSTNAME|* *|LASTNAME|*, thanks for signing up.'
    // Needs to be set due to "funkiness" in MailChimp API that requires a value
    // regardless of the use of a template.
    $templateContent = array(
      array(
          'name' => 'main',
          'content' => ''
      ),
    );

    $mandrillResults = $mandrill->messages->sendTemplate($this->template, $templateContent, $this->message);
    echo '-> mbc-transactional-email Mandrill message sent: ' . $payloadDetails['email'] . ' - ' . date('D M j G:i:s T Y'), PHP_EOL;

    // Log email address issues returned from Mandrill
    if (isset($mandrillResults[0]['reject_reason']) && $mandrillResults[0]['reject_reason'] != NULL) {
      $this->statHat->addStatName('Mandrill reject_reason: ' . $mandrillResults[0]['reject_reason']);
    }

    // Remove from queue if Mandrill responds without configuration error
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
    else {
      echo '-> mbc-transactional-email Mandrill message ERROR: ' . print_r($mandrillResults, TRUE) . ' - ' . date('D M j G:i:s T Y'), PHP_EOL;
    }

  }

}
