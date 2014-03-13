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

  $credentials['host'] = getenv("RABBITMQ_HOST");
  $credentials['port'] = getenv("RABBITMQ_PORT");
  $credentials['username'] = getenv("RABBITMQ_USERNAME");
  $credentials['password'] = getenv("RABBITMQ_PASSWORD");

  if (getenv("RABBITMQ_VHOST") != FALSE) {
    $credentials['vhost'] = getenv("RABBITMQ_VHOST");
  }
  else {
    $credentials['vhost'] = '';
  }

  // Set config vars
  $exchangeName = $config['exchange']['name'] = getenv("MB_TRANSACTIONAL_EXCHANGE");
  $config['exchange']['type'] = getenv("MB_TRANSACTIONAL_EXCHANGE_TYPE");
  $config['exchange']['passive'] = getenv("MB_TRANSACTIONAL_EXCHANGE_PASSIVE");
  $config['exchange']['durable'] = getenv("MB_TRANSACTIONAL_EXCHANGE_DURABLE");
  $config['exchange']['auto_delete'] = getenv("MB_TRANSACTIONAL_EXCHANGE_AUTO_DELETE");
  $config['exchange']['routing_key'] = getenv("MB_TRANSACTIONAL_EXCHANGE_ROUTING_KEY");

  $queueName = $config['queue']['name'] = getenv("MB_TRANSACTIONAL_QUEUE");
  $config['queue']['passive'] = getenv("MB_TRANSACTIONAL_QUEUE_PASSIVE");
  $config['queue']['durable'] = getenv("MB_TRANSACTIONAL_QUEUE_DURABLE");
  $config['queue']['exclusive'] = getenv("MB_TRANSACTIONAL_QUEUE_EXCLUSIVE");
  $config['queue']['auto_delete'] = getenv("MB_TRANSACTIONAL_QUEUE_AUTO_DELETE");

  $routingKey = $config['routingKey'] = getenv("MB_USER_REGISTRATION_EXCHANGE_ROUTING_KEY");

  // Load messagebroker-phplib class
  $MessageBroker = new MessageBroker($credentials, $config);

  // Collect RabbitMQ connection details
  $connection = $MessageBroker->connection;
  $channel = $connection->channel();

  // Queue
  $channel = $MessageBroker->setupQueue($queueName, $channel);

  // Exchange
  $channel = $MessageBroker->setupExchange($exchangeName, 'topic', $channel);

  // Bind exchange to queue for 'transactional' key
  // queue_bind($queue, $exchange, $routing_key="", $nowait=false, $arguments=null, $ticket=null)
  $channel->queue_bind($queueName, $exchangeName, '*.*.transactional');

  echo "\n\n";
  echo '~~~~~~~~~~~~~~~~~~~~~~~~~', "\n";
  echo ' mbc-transactional-email', "\n";
  echo '~~~~~~~~~~~~~~~~~~~~~~~~~', "\n\n";

  echo ' [*] Queue: ' . $queueName, "\n";
  echo ' [*] Exchange: ' . $exchangeName, "\n";
  echo ' [*] Binding: *.*.transactional', "\n\n";

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
  $channel->basic_consume($queueName, $routingKey, false, false, false, false, 'ConsumeCallback');

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
  if (empty($payload->email)) {
    trigger_error('Invalid Payload - Email address in payload is required.', E_USER_WARNING);
    return FALSE;
  }

  $merge_vars = array();

  foreach ($payload->merge_vars as $varName => $varValue) {
    $merge_vars[] = array(
      'name' => $varName,
      'content' => $varValue
    );
  }

  $message = array(
    'html' => '<p>This is a test message with Mandrill\'s PHP wrapper!.</p>',
    'to' => array(
      array(
        'email' => $payload->email,
        'name' => $payload->merge_vars->FNAME,
      )
    ),
    'merge_vars' => array(
      array(
        'rcpt' => $payload->email,
        'vars' => $merge_vars
      ),
    ),
    'tags' => array(
      $payload->activity
    )
  );

  // Select template based on payload details
  switch ($payload->activity) {
    case 'user_register':
    case 'user-register':
      $templateName = 'mb-general-signup';
      break;
    case 'user_password':
    case 'user-password':
      $templateName = 'mb-password-reset';
      break;
    case 'campaign_signup':
    case 'campaign-signup':
      $templateName = 'mb-campaign-signup';
      $message['tags'][] = $payload->event_id;
      break;
    case 'campaign-reportback':
    case 'campaign_reportback':
      $templateName = 'mb-campaign-report-back';
      $message['tags'][] = $payload->event_id;
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

    echo '-------------------', "\n";
    echo ' [x] Received payload:' . $payload->body, "\n\n";

    // Assemble message details
    // $payloadDetails = unserialize($payload->body);
    $payloadDetails = json_decode($payload->body);
    list($templateName, $templateContent, $message) = BuildMessage($payloadDetails);

    // Send message if no errors from building message
    if ($templateName != FALSE) {

      echo ' [x] Built message contents...', "\n";

      // Send message
      $mandrillResults = $Mandrill->messages->sendTemplate($templateName, $templateContent, $message);

      $mandrillResults = print_r($mandrillResults, TRUE);

      echo ' [x] Sent message via Mandrill:', "\n";
      echo ' Returned from Mandrill: ' .$mandrillResults, "\n";

      echo ' [x] Done - acknowledgement with delivery tag ' . $payload->delivery_info['delivery_tag'] . ' sent. ', "\n\n";
      $payload->delivery_info['channel']->basic_ack($payload->delivery_info['delivery_tag']);

      echo "\n\n";

    }

}
