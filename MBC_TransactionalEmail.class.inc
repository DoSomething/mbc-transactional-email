<?PHP

use DoSomething\MBStatTracker\StatHat;

/**
 * MBC_TransactionalEmail class - functionality related to the Message Broker
 * producer mbc-transactional-email.
 */
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
    $this->statHat->setIsProduction(isset($settings['use_stathat_tracking']) ? $settings['use_stathat_tracking'] : FALSE);
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

    echo '------- mbc-transactional-email - consumeTransactionalQueue() START -------', PHP_EOL;

    // Use the Mandrill service
    $mandrill = new Mandrill();

    // Assemble message details
    // $payloadDetails = unserialize($payload->body);
    $payloadDetails = unserialize($payload->body);

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

    echo '------- mbc-transactional-email - consumeTransactionalQueue() END -------', PHP_EOL;
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
      echo '------- mbc-transactional-email - buildMessage ERROR, missing email: ' . print_r($payload, TRUE) . ' - ' . date('D M j G:i:s T Y') . ' -------', PHP_EOL;
      $this->statHat->addStatName('buildMessage: Error - email address blank.');
      return FALSE;
    }

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

    return array($templateName, $templateContent, $message);

  }

}
