<?php
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

  // Use AMQP
  use PhpAmqpLib\Connection\AMQPConnection;
  use PhpAmqpLib\Message\AMQPMessage;

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
  );

  // Load messagebroker-phplib class
  $MessageBroker = new MessageBroker($credentials, $config);

  // Collect RabbitMQ connection details
  $connection = $MessageBroker->connection;
  $channel = $connection->channel();

  // Queue
  list($channel, ) = $MessageBroker->setupQueue($config['queue']['transactional']['name'], $channel);

  // Exchange
  $channel = $MessageBroker->setupExchange($config['exchange']['name'], $config['exchange']['type'], $channel);

  // Bind exchange to queue for 'transactional' key
  // queue_bind($queue, $exchange, $routing_key="", $nowait=false, $arguments=null, $ticket=null)
  $channel->queue_bind($config['queue']['transactional']['name'], $config['exchange']['name'], $config['queue']['transactional']['bindingKey']);

  echo "\n\n";
  echo '~~~~~~~~~~~~~~~~~~~~~~~~~', "\n";
  echo ' mbc-transactional-email', "\n";
  echo '~~~~~~~~~~~~~~~~~~~~~~~~~', "\n\n";

  echo ' [*] Queue: ' . $config['queue']['transactional']['name'], "\n";
  echo ' [*] Exchange: ' . $config['exchange']['name'], "\n";
  echo ' [*] Binding: ' . $config['queue']['transactional']['bindingKey'], "\n\n";

  echo ' [*] Waiting for messages. To exit press CTRL+C', "\n\n";

  // Fair dispatch
  // Don't give more than one message to a worker at a time. Don't dispatch a new
  // message to a worker until it has processed and acknowledged the previous one.
  // Instead, it will dispatch it to the next worker that is not still busy.
  // AKA: unlimited number of workers with even distribution of tasks based on
  // completion
  // prefetch_count = 1
  // $channel->basic_qos(null, 1, null);

  // Message acknowledgments are turned off by default.  Fourth parameter in
  // basic_consume to false (true means no ack). This will send an acknowledgment
  // from the worker once the task is complete.
  // basic_consume($queue="", $consumer_tag="", $no_local=false, $no_ack=false,
  //   $exclusive=false, $nowait=false, $callback=null, $ticket=null)
  // $channel->basic_consume($queueName, $routingKey, false, false, false, false, 'ConsumeCallback');
  $channel->basic_consume($config['queue']['transactional']['name'], '', false, false, false, false, 'ConsumeCallback');

  // To see message that have not been "unack"ed.
  // $ rabbitmqctl list_queues name messages_ready messages_unacknowledged

  // The code will block while $channel has callbacks. Whenever a message is
  // received the $callback function will be passed the received message.
  while(count($channel->callbacks)) {
      $channel->wait();
  }

  $channel->close();
  $connection->close();

/*
 * BuildMessage()
 * Assembly of message based on Mandrill API: Send-Template
 * https://mandrillapp.com/api/docs/messages.JSON.html#method=send-template
 *
 * @param object $payload
 *   The email address that the message will be built for.
 */
function BuildMessage($payload) {

  // Validate payload
  if (empty($payload['email'])) {
    trigger_error('Invalid Payload - Email address in payload is required.', E_USER_WARNING);
    return FALSE;
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
    'tags' => array(
      $payload['activity']
    )
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

  // Select template based on payload details
  switch ($payload['activity']) {
    case 'user_register':
      $templateName = 'mb-general-signup';
      break;
    case 'user_password':
      $templateName = 'mb-password-reset';
      break;
    case 'campaign_signup':
      $templateName = 'mb-campaign-signup';
      $message['tags'][] = $payload['event_id'];
      break;
    case 'campaign_group_signup':
      $templateName = 'mb-group-campaign-signup';
      $message['tags'][] = $payload['event_id'];
      break;
    case 'campaign_reportback':
      $templateName = 'mb-campaign-report-back';
      $message['tags'][] = $payload['event_id'];
      break;
    default:
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
function ConsumeCallback($payload) {

    // Use the Mandrill service
    $Mandrill = new Mandrill();

    echo '------- ' . date('D M j G:i:s:u T Y') . ' -------', "\n";
    echo ' [x] Received payload:' . $payload->body, "\n\n";

    // Assemble message details
    // $payloadDetails = unserialize($payload->body);
    $payloadDetails = unserialize($payload->body);
    list($templateName, $templateContent, $message) = BuildMessage($payloadDetails);

    // Send message if no errors from building message
    if ($templateName != FALSE) {

      echo ' [x] Built message contents...', "\n";
      
      try
      {

        // Send message
        $mandrillResults = $Mandrill->messages->sendTemplate($templateName, $templateContent, $message);

        echo ' [x] Sent message via Mandrill:', "\n";
        echo ' Returned from Mandrill: ' . print_r($mandrillResults, TRUE), "\n";

        echo ' [x] Done - acknowledgement with delivery tag ' . $payload->delivery_info['delivery_tag'] . ' sent. ', "\n\n";
        $payload->delivery_info['channel']->basic_ack($payload->delivery_info['delivery_tag']);

        echo "\n\n";
        
      }
      catch(Exception $e) {
        throw new Exception( 'Failed to send email through Mandrill. Returned results: ' . print_r($mandrillResults, TRUE), 0, $e);
        // $payload->delivery_info['channel']->basic_ack($payload->delivery_info['delivery_tag']);
      }

    }

}
