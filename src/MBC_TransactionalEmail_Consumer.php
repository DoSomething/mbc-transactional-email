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
   * $callback = function()
   *   A callback function for basic_consume() that will manage the sending of a
   *   request to Mandrill based on the details in $payload
   *
   * @param string $payload
   *  An JSON array of the details of the message to be sent
   */
  public function consumeTransactionalQueue($payload) {

    echo '-------  mbc-transactional-email - MBC_TransactionalEmail_Consumer->consumeTransactionalQueue() START -------', PHP_EOL;

    parent::consumeQueue($message);
    echo PHP_EOL . PHP_EOL;
    echo '** Consuming: ' . $this->message['email'], PHP_EOL;

    if ($this->canProcess()) {

      echo '- canProcess(): OK', PHP_EOL;
      $setterOK = $this->setter($this->message);

      // Build out user object and gather / trigger building campaign objects
      // based on user campaign activity
      if ($setterOK) {
        echo '- setter(): OK', PHP_EOL;
        $this->process();
      }
    }

    // @todo: Throdle the number of consumers running. Based on the number of messages
    // waiting to be processed start / stop consumers.
    $queueMessages = parent::queueStatus('digestUserQueue');
    echo '- queueMessages ready: ' . $queueMessages['ready'], PHP_EOL;
    echo '- queueMessages unacked: ' . $queueMessages['unacked'], PHP_EOL;

    $this->messageBroker->sendAck($this->message['payload']);
    echo '- Ack sent: OK', PHP_EOL . PHP_EOL;
    

      
      
    
    
    
    list($templateName, $templateContent, $message) = $this->buildMessage($payloadDetails);

    // Send message if no errors from building message
    if ($templateName != FALSE) {

      // Send message
      try {

        $mandrillResults = $mandrill->messages->sendTemplate($templateName, $templateContent, $message);
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
      catch(Exception $e) {
        echo 'Message: ' .$e->getMessage();
        $this->statHat->addStatName('campaign_signup Mandrill: ERROR');
      }

      // All addStatName stats will be incremented by one at the end of the callback.
      $this->statHat->reportCount(1);

    }
    else {
      echo '------- mbc-transactional-email - consumeTransactionalQueue - buildMessage ERROR - ' . date('D M j G:i:s T Y') . ' -------', PHP_EOL;
      $this->messageBroker->sendAck($payload);
    }

    echo '-------  mbc-transactional-email - MBC_TransactionalEmail_Consumer->consumeTransactionalQueue() END -------', PHP_EOL;
  }
  
  /**
   *
   */
  private function canProcess() {
    
         // Validate payload
    if (empty($payload['email'])) {
      trigger_error('Invalid Payload - Email address in payload is required.', E_USER_WARNING);
      echo '------- mbc-transactional-email - buildMessage ERROR, missing email: ' . print_r($payload, TRUE) . ' - ' . date('D M j G:i:s T Y') . ' -------', PHP_EOL;
      $this->statHat->addStatName('buildMessage: Error - email address blank.');
      return FALSE;
    }

  }

  /**
   *
   */
  private function setter() {
    


    if (isset($payload['email_tags']) && is_array($payload['email_tags'])) {
      $tags = $payload['email_tags'];
    }
    elseif (isset($payload['tags']) && is_array($payload['tags']))  {
      $tags = $payload['tags'];
    }
    else {
      $tags = array(
        0 => $payload['activity'],
      );
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
      echo 'Template not defined: ' . print_r($payload, TRUE), PHP_EOL;
      $templateName = FALSE;
    }

    // example: 'content' => 'Hi *|FIRSTNAME|* *|LASTNAME|*, thanks for signing up.'
    $templateContent = array(
      array(
          'name' => 'main',
          'content' => ''
      ),
    );

  }

  /**
   *
   */
  private function process() {
    
         // Send message
      try {

        $mandrillResults = $mandrill->messages->sendTemplate($templateName, $templateContent, $message);
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
      catch(Exception $e) {
        echo 'Message: ' .$e->getMessage();
        $this->statHat->addStatName('campaign_signup Mandrill: ERROR');
      }

  }

}
