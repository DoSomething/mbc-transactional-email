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
  $queueName = $config['queue']['name'] = getenv("MB_TRANSACTIONAL_QUEUE");

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

  echo ' [*] Waiting for messages. To exit press CTRL+C', "\n";

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
  $channel->basic_consume($queueName, 'transactionals', false, false, false, false, 'ConsumeCallback');

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
    trigger_error('Invalid Payload - Email address in payload is required.', E_WARNING);
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
    'from_email' => $payload->email,
    'from_name' => $payload->merge_vars->FNAME,
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
      $templateName = 'mb-general-site-signup';
      break;
    case 'user_password':
      $templateName = 'mb-password-reset';
      break;
    case 'campaign_signup':
      $templateName = 'mb-campaign-signup';
      $message['tags'][] = $payload->event_id;
      break;
    case 'campaign_reportback':
      $templateName = 'mb-campaign-report-back';
      $message['tags'][] = $payload->event_id;
      break;
    default:
      $templateName = 'ds-message-broker-default-01';
  }

  $templateContent = array(
    array(
        'name' => 'main',
        'content' => 'Hi *|FIRSTNAME|* *|LASTNAME|*, thanks for signing up.'),
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

    echo(" [x] Received payload: " . $payload->body . "<br /><br />");

    // Assemble message details
    // $payloadDetails = unserialize($payload->body);
    $payloadDetails = json_decode($payload->body);
    list($templateName, $templateContent, $message) = BuildMessage($payloadDetails);

    // Send message if no errors from building message
    if ($templateName != FALSE) {

      echo(" [x] Built message contents...<br /><br />");

      // Send message
      $mandrillResults = $Mandrill->messages->sendTemplate($templateName, $templateContent, $message);

      $mandrillResults = print_r($mandrillResults, TRUE);

      echo(" [x] Sent message via Mandrill:<br />");
      echo($mandrillResults);

      echo(" [x] Done<br /><br />");
      $payload->delivery_info['channel']->basic_ack($payload->delivery_info['delivery_tag']);

    }

}