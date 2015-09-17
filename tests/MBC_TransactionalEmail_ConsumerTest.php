<?php
/**
 * Test coverage for MBC_TransactionalEmail_Consumer class.
 */

use DoSomething\MBC_TransactionalEmail\MBC_TransactionalEmail_Consumer;

  // Including that file will also return the autoloader instance, so you can store
  // the return value of the include call in a variable and add more namespaces.
  // This can be useful for autoloading classes in a test suite, for example.
  // https://getcomposer.org/doc/01-basic-usage.md
  $loader = require_once __DIR__ . '/../vendor/autoload.php';
  
/**
 *  MBC_TransactionalEmail_ConsumerTest(): Test process for testing MBC_TransactionalEmail_Consumer
 *  properties and methods.
 */
class  MBC_TransactionalEmail_ConsumerTest extends PHPUnit_Framework_TestCase {
  
  /**
   * Steps for setup of test class.
   */
  public function setUp(){ }
  
  /**
   * Tear down steps for test class.
   */
  public function tearDown(){ }
 
  /**
   * Test coverage for consumeTransactionalQueue() method.
   */
  public function testConsumeTransactionalQueue()
  {

    date_default_timezone_set('America/New_York');

    // Load Message Broker settings used mb mbp-user-import.php
    define('CONFIG_PATH',  __DIR__ . '/../messagebroker-config');
    require_once __DIR__ . '/../mbc-transactional-email.config.inc';

    // Create MBC_TransactionalEmail_Consumer object to access ?? method for testing
    $mbcTransactionalEmail = new MBC_TransactionalEmail_Consumer();

    
    $this->assertTrue(TRUE);
  }
 
}
