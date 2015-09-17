<?php
/**
 * Configuration settings for mbc-transactional-email.
 *
 * Message Broker configuration settings for mbc-user-digest application. The MB_Configuration class
 * is used to create a singleton instance that can be referenced anywhere in the application for
 * application configuration settings.
 *
 * @package mbc-transactional-email
 * @link    https://github.com/DoSomething/mbc-transactional-email
 */

/**
 * Load configuration settings into singleton instance with MB_Configuration class.
 */
use DoSomething\MB_Toolbox\MB_Configuration;
use DoSomething\StatHat\Client as StatHat;
use DoSomething\MB_Toolbox\MB_Toolbox_cURL;

// Load configuration settings common to the Message Broker system
// symlinks in the project directory point to the actual location of the files
require_once __DIR__ . '/messagebroker-config/mb-secure-config.inc';

$mbConfig = MB_Configuration::getInstance();

$generalSettings = $mbConfig->gatherSettings('general');
$mbConfig->setProperty('generalSettings', [
  'default' => [
    'first_name' => $generalSettings->default->first_name
  ],
  'email' => [
    'from' => $generalSettings->email->from,
    'name' => $generalSettings->email->name,
  ]
]);

$mbConfig->setProperty('rabbit_credentials', [
  'host' =>  getenv("RABBITMQ_HOST"),
  'port' => getenv("RABBITMQ_PORT"),
  'username' => getenv("RABBITMQ_USERNAME"),
  'password' => getenv("RABBITMQ_PASSWORD"),
  'vhost' => getenv("RABBITMQ_VHOST"),
]);
$mbConfig->setProperty('rabbitapi_credentials', [
  'host' =>  getenv("MB_RABBITMQ_MANAGEMENT_API_HOST"),
  'port' => getenv("MB_RABBITMQ_MANAGEMENT_API_PORT"),
  'username' => getenv("MB_RABBITMQ_MANAGEMENT_API_USERNAME"),
  'password' => getenv("MB_RABBITMQ_MANAGEMENT_API_PASSWORD"),
]);

// Create connection to exchange and queue for processing of queue contents.
$mbRabbitConfig = $mbConfig->constructRabbitConfig('transactionalExchange', array('transactionalQueue'));
$mbConfig->setProperty('messageBroker_config', $mbRabbitConfig);

$rabbitCredentials = $mbConfig->getProperty('rabbit_credentials');
$messageBrokerConfig = $mbConfig->getProperty('messageBroker_config');
$mbConfig->setProperty('messageBroker', new MessageBroker($rabbitCredentials, $messageBrokerConfig));

$mbConfig->setProperty('mandrill', new Mandrill(
  getenv("MANDRILL_APIKEY")
));

$mbConfig->setProperty('statHat', new StatHat([
  'ez_key' => getenv("STATHAT_EZKEY"),
  'debug' => getenv("DISABLE_STAT_TRACKING")
]));

$mbConfig->setProperty('mbToolboxcURL', new MB_Toolbox_cURL());
