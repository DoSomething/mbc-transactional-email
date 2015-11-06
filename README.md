mbc-transactional-email
==============
Message Broker consumer that processes messages sent to the transactionalQueue to generate transactional email messages sent via Mandrill (http://mandrill.com).

####Run tests:
`./vendor/bin/phpunit tests`

####Install with:
`composer install --no-dev`

To include PHPUnit functionality:
`composer install --dev`

####Run application:
`APP_ENV="<production | development | test>"`
`export APP_ENV`
`php mbc-transactional-email.php`

The APP_ENV setting manages what connection configuration settings are used.

####Updates:
`composer update`
- will perform:
  - `git update`
  - dependency updates
  - run tests

####Documentation when install is done with `--dev`:
./docs/index.html

####Parallelization
The script is configured to consume the transactionalQueue one message at a time with acknowledgements (message is removed from the queue only after the consumer sends confirmation that the message has been processed). Adding additional daemon process to consume the queue will result in parallelization for an unlimited number of consumers with a linear increase in the rate of processing.

####Composer options
Before deploying to production, don't forget to optimize the autoloader
- `composer dump-autoload --optimize`

Exclude development packages
- `composer install --no-dev`
