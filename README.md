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

####Architecture Diagram
An example transactional event ("`user_registration`") that includes the `mbc-transactional-email` application as a consumer. This consumer consumes messages from the `transactionalQueue` to generate cURL requests to the Mandrill API to generate an email message.

![User Registration Transaction message flow](https://raw.githubusercontent.com/DoSomething/mbc-transactional-email/master/resources/DoSomethingUserRegistration_Architecture.png)

This diagram source can be found in `/resources` as an XML file created using [Draw.io](http://draw.io).

## Docker
### Running mbc-transactional-email in Docker container
First of all, you need to obtain `mb-secure-config`.  
Let's say, it is saved to `$HOME/Development/mb/mb-secure-config/docker-dev/mb-secure-config.inc`.


Then you can start the consumer with:  
`docker run -v $HOME/Development/mb/mb-secure-config/docker-dev/mb-secure-config.inc:/usr/src/mb/messagebroker-config/mb-secure-config.inc:ro -d --name=mbc-transactional-email dosomething/mbc-transactional-email:latest`


### Building images with Docker
`docker build --build-arg COMPOSER_GITHUB_TOKEN=your_github_token -t dosomething/mbc-transactional-email:latest .`
