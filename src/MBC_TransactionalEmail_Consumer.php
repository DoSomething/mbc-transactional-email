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
   *
   * @var array $request
   */
  protected $request;

  /**
   * The name of the Madrill template to format the message with.
   *
   * @var array $template
   */
  protected $template;

  /**
   * Override campaign signup and reportback templates.
   *
   * @var array
   *   An array in the following format:
   *     nid => template_suffix
   */
  protected $campaignSignupOverrideSuffix = [
    // Suspended for WHAT?: AMPLIFY
    // https://www.dosomething.org/us/campaigns/suspended-what-amplify
    7661 => 'amplify',
    // Suspended for WHAT?: ADVOCATE
    // https://www.dosomething.org/us/campaigns/suspended-what-advocate
    7662 => 'advocate',
    // Sincerely, Us
    // https://www.dosomething.org/us/campaigns/sincerely-us
    7656 => 'sincerely-us',
    // Unlock the Truth
    // https://www.dosomething.org/us/campaigns/uncover-truth
    7771 => 'uncover-the-truth',
    // Mirror Messages
    // https://www.dosomething.org/us/campaigns/mirror-messages
    7 => 'mirror-messages',
    // All in We Win
    // https://www.dosomething.org/us/campaigns/all-we-win
    7831 => 'all-in-we-win',
    // Steps for Soldiers
    // https://www.dosomething.org/us/campaigns/steps-soldiers
    7822 => 'steps-for-soldiers',
    // 5 Days, 5 Actions
    // https://www.dosomething.org/us/campaigns/5-days-5-actions
    7889 => '5days-5actions',
  ];

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

    try {

      if ($this->canProcess()) {
        $this->setter($this->message);
        // Should be after setter() because template name is resolve in it.
        $this->logConsumption('email');
        $this->process();
      }
      elseif (empty($this->message['email'])) {
        echo '- failed canProcess(), email not defined. Removing from queue.', PHP_EOL;
        $this->messageBroker->sendAck($this->message['payload']);
      }
      else {
        echo '- ' . $this->message['email'] . ' failed canProcess(), removing from queue.', PHP_EOL;
        $this->messageBroker->sendAck($this->message['payload']);
      }

    }
    catch(Exception $e) {
      echo '**ALERT: ' . $e->getMessage(), PHP_EOL;
      $this->messageBroker->sendAck($this->message['payload']);
      parent::deadLetter($this->message, 'MBC_TransactionalEmail_Consumer->consumeTransactionalQueue() Error', $e->getMessage());
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

    if (isset($this->message['transactionals']) && $this->message['transactionals'] == false) {
      echo '- canProcess(), transactionals disabled.', PHP_EOL;
      return false;
    }

    if (empty($this->message['email'])) {
      echo '- canProcess(), email not set.', PHP_EOL;
      return false;
    }

    if (preg_match('/@.*\.import$/', $this->message['email'])) {
      echo '- canProcess(), import placeholder address: ' . $this->message['email'], PHP_EOL;
      return false;
    }

    if (!empty($this->message['activity']) && $this->message['activity'] === 'campaign_signup') {
      if (!empty($this->message['event_id']) && !empty($this->campaignSignupOverrideSuffix[$this->message['event_id']])) {
        echo '- processing exceptin campaign signup ' . $this->message['event_id'] . '.' . PHP_EOL;
      } else {
        echo '- canProcess(), processing campaign signups is deprecated in favor of mbc-transactional-digest.', PHP_EOL;
        return false;
      }
    }

    if (!empty($this->message['activity']) && $this->message['activity'] === 'user_register') {
      echo '- canProcess(), processing user registrations is deprecated in favor of customer.io.', PHP_EOL;
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

    // Needed to support email-template rather than email_template.
    // Product of a legacy bug / non-standard var name in various other producer apps that
    // send messages to this consumer.
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
    // Rejected with reason.
    if (!empty($mandrillResults[0]['reject_reason'])) {
      $rejectReason = &$mandrillResults[0]['reject_reason'];
      // StatHat graph.
      $statName .= 'Error: ' . $rejectReason;

      $skipRejectReasons = [
        'hard-bounce',
        'soft-bounce',
        'spam',
      ];

      // Parse reject reason:
      // 1. Retry on remote server errors.
      if (strpos($rejectReason, '500 Internal Server Error') || strpos($rejectReason, '502 Bad Gateway')) {
        sleep(30);
        $this->messageBroker->sendNack($this->message['payload']);
      }
      // 2. Ignore hard, soft bounces and spam.
      elseif (in_array($rejectReason, $skipRejectReasons)) {
        $this->messageBroker->sendAck($this->message['payload']);
        echo '** mbc-transactional-email: '
             . 'Skipping '
             . $this->request['to'][0]['email']
             . ', it is rejected as ' . $rejectReason . '.'
             . ' - ' . date('D M j G:i:s T Y')
             . PHP_EOL;
      }
      // 3. Throw an exception with reject reason status.
      else {
        $errorDetails = [
          'email' => $mandrillResults[0]['email'],
          'error' => $rejectReason,
          'code' => '000'
        ];
        $payload = serialize($errorDetails);
        $this->messageBroker->publish($payload, 'user.mailchimp.error');
        throw new Exception(print_r($mandrillResults[0], true));
      }
    }
    // Accepted.
    elseif (isset($mandrillResults[0]['status']) && $mandrillResults[0]['status'] != 'error') {
      echo '-> mbc-transactional-email Mandrill message sent: ' . $this->request['to'][0]['email'] . ' - ' . date('D M j G:i:s T Y'), PHP_EOL;
      $this->messageBroker->sendAck($this->message['payload']);
      $statName .= 'OK';
    }
    // Unknown state.
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
    // `campaign_signup_single` is the fallback to normal email when user activity
    // isn't qualified for the transactional digest.
    // However, `campaign_signup_single` should be processed exactly like
    // the old transactional signup, including template naming.
    if ($message['activity'] === 'campaign_signup_single') {
      $message['activity'] = 'campaign_signup';
    }

    $activity = str_replace('_', '-', $message['activity']);
    $userCountry = '';
    if (isset($message['user_country'])) {
      $userCountry = strtoupper($message['user_country']);
    }
    if (empty($userCountry) && isset($message['email_template'])) {
      $userCountry = strtoupper($this->mbToolbox->countryFromTemplateName($message['email_template']));
    }
    $campaignLanguage = '';
    if (isset($message['campaign_language'])) {
      $campaignLanguage = strtolower($message['campaign_language']);
    }

    switch ($message['activity']):

      case "user_password":

        // mb-campaign-signup-KR
        if ($message['user_country'] === 'US') {
          $templateName = $message['email_template'];
        }
        elseif ($this->mbToolbox->isDSAffiliate($userCountry)) {
          $templateName = $message['email_template'];
        }
        else {
          $templateName = 'mb-' . $activity . '-GL';
        }
        break;

      case "campaign_signup":
      case "campaign_reportback":

        switch ($campaignLanguage):

          case 'en':
            if ($userCountry === 'US') {
              $templateName = 'mb-' . $activity . '-US';

              // Override template from exceptions array.
              $override = !empty($message['event_id']) && !empty($this->campaignSignupOverrideSuffix[$message['event_id']]);
              if ($override) {
                $templateName .= '-' . $this->campaignSignupOverrideSuffix[$message['event_id']];
              }
            }
            else {
              $templateName = 'mb-' . $activity . '-XG';
            }
            break;

          case 'en-global':
            $templateName = 'mb-' . $activity . '-XG';
            break;

          case 'es-mx':
            $templateName = 'mb-' . $activity . '-MX';
            break;

          case 'pt-br':
            $templateName = 'mb-' . $activity . '-BR';
            break;

          default:

            // Support old affiliate mulitsites that don't send user or campaign language details.
            if ($this->mbToolbox->isDSAffiliate($userCountry)) {
              $templateName = $message['email_template'];
            }
            else {
              $templateName = 'mb-' . $activity . '-GL';
            }
           break;

        endswitch;

        break;

      case "campaign_signup_digest":

        if (isset($message['email_template'])) {
          $templateName = $message['email_template'];
        }
        else {
          $templateName = false;
        }

        break;

      case "vote":

        if (isset($message['email_template'])) {
          $templateName = $message['email_template'];
        }
        elseif (isset($message['application_id']) && isset($userCountry) && $this->mbToolbox->isDSAffiliate($userCountry)) {
          $templateName = 'mb-' . $message['application_id'] . '-vote-' . $userCountry;
        }
        elseif (isset($message['application_id'])) {
          $templateName = 'mb-' . $message['application_id'] . '-vote-XG';
        }
        else {
          $templateName = false;
        }
        break;

      case "user_password-niche":
      case "user_welcome-niche":

         $templateName = $message['email_template'];
         break;

      case "mb-reports":

        $templateName = $message['email_template'];
        break;

       default:
         $templateName = false;
         break;

    endswitch;

    if (!$templateName) {
      $statName = 'mbc-transactional-email: Invalid Template';
      $this->statHat->ezCount($statName, 1);
    }
    else {
      $statName = 'mbc-transactional-email: activity: ' . $message['activity'];
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
    echo '** Consuming ' . $targetName . ': ' . $this->message[$targetName] . PHP_EOL;
    echo '- Template: ' . $this->template . PHP_EOL;
    if (isset($this->message['activity'])) {
       echo '- Activity: ' . $this->message['activity'] . PHP_EOL;
    }
    if (isset($this->message['user_country'])) {
       echo '- User country: ' . $this->message['user_country'] . PHP_EOL;
    }
    if (isset($this->message['campaign_language'])) {
       echo '- Campaign language: ' . $this->message['campaign_language'] . PHP_EOL;
    }
  }
}
