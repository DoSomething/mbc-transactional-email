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
use DoSomething\MB_Toolbox\MB_Configuration;

// Load configuration settings common to the Message Broker system
// symlinks in the project directory point to the actual location of the files
require_once __DIR__ . '/messagebroker-config/mb-secure-config.inc';
require_once __DIR__ . '/MBC_TransactionalEmail.class.inc';

$credentials = array(
  'host' =>  getenv("RABBITMQ_HOST"),
  'port' => getenv("RABBITMQ_PORT"),
  'username' => getenv("RABBITMQ_USERNAME"),
  'password' => getenv("RABBITMQ_PASSWORD"),
  'vhost' => getenv("RABBITMQ_VHOST"),
);

$settings = array(
  'stathat_ez_key' => getenv("STATHAT_EZKEY"),
);

$config = array();
$source = __DIR__ . '/messagebroker-config/mb_config.json';
$mb_config = new MB_Configuration($source, $settings);
$transactionalExchange = $mb_config->exchangeSettings('transactionalExchange');

$config['exchange'] = array(
  'name' => $transactionalExchange->name,
  'type' => $transactionalExchange->type,
  'passive' => $transactionalExchange->passive,
  'durable' => $transactionalExchange->durable,
  'auto_delete' => $transactionalExchange->auto_delete,
);
foreach ($transactionalExchange->queues->transactionalQueue->binding_patterns as $bindingCount => $bindingKey) {
  $config['queue'][$bindingCount] = array(
    'name' => $transactionalExchange->queues->transactionalQueue->name,
    'passive' => $transactionalExchange->queues->transactionalQueue->passive,
    'durable' =>  $transactionalExchange->queues->transactionalQueue->durable,
    'exclusive' =>  $transactionalExchange->queues->transactionalQueue->exclusive,
    'auto_delete' =>  $transactionalExchange->queues->transactionalQueue->auto_delete,
    'bindingKey' => $bindingKey,
  );
}

$config['consume'] = array(
  'no_local' => $transactionalExchange->queues->transactionalQueue->consume->no_local,
  'no_ack' => $transactionalExchange->queues->transactionalQueue->consume->no_ack,
  'nowait' => $transactionalExchange->queues->transactionalQueue->consume->nowait,
  'exclusive' => $transactionalExchange->queues->transactionalQueue->consume->exclusive,
);


// Kick off
echo '------- mbc-transactional-email START: ' . date('D M j G:i:s T Y') . ' -------', PHP_EOL;

$mb = new MessageBroker($credentials, $config);
$mb->consumeMessage(array(new MBC_TransactionalEmail($mb, $settings), 'consumeTransactionalQueue'));

echo '------- mbc-transactional-email END: ' . date('D M j G:i:s T Y') . ' -------', PHP_EOL;

