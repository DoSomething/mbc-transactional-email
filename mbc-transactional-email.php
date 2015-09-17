<?php
/**
 * mbc-transactional-email: Manage sending transactional email message.
 *
 * Process entries in the transactionalQueue. Each entry will result in a call
 * to the Mandrill API to send an email address.
 *
 * @package mbc-transactional-email
 * @link    https://github.com/DoSomething/mbc-transactional-email
 */

 /**
  * Base values to coordinate the script being a daemon process.
  *
  * Loading application configuration settings then go into a wait state listening to the
  * transactionalQueue. When a message arrives the callback in the MBC_TransactionalEmail_Consumer
  * is triggered.
  */
date_default_timezone_set("America/New_York");
define('CONFIG_PATH',  __DIR__ . '/messagebroker-config');
// The number of messages for the consumer to reserve with each callback
// See consumeMwessage for further details.
// Necessary for parallel processing when more than one consumer is running on the same queue.
define('QOS_SIZE', 1);

// Load up the Composer autoload magic
require_once __DIR__ . '/vendor/autoload.php';
use DoSomething\MBC_TransactionalEmail\MBC_TransactionalEmail_Consumer;

require_once __DIR__ . '/mbc-transactional-email.config.inc';


// Kick off
echo '------- mbc-transactional-email START: ' . date('j D M Y G:i:s T') . ' -------', PHP_EOL;

$mb = $mbConfig->getProperty('messageBroker');
$mb->consumeMessage(array(new MBC_TransactionalEmail_Consumer(), 'consumeTransactionalQueue'), QOS_SIZE);

echo '------- mbc-transactional-email END: ' . date('j D M Y G:i:s T') . ' -------', PHP_EOL;
