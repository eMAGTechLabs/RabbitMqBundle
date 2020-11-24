<?php

namespace OldSound\RabbitMqBundle\Declarations;

use OldSound\RabbitMqBundle\RabbitMq\Consumer;
use PhpAmqpLib\Connection\AbstractConnection;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;

class ConsumerFactory
{
    use ContainerAwareTrait;

    /** @var ConsumerDef[] */
    private $consumerDefs = [];

    public function addConsumer()
    {

    }

    public function create($name): Consumer
    {
        $consumerDef = $this->consumerDefs[$name];
        /** @var AbstractConnection $connection */
        $connection = $this->container->get(sprintf('old_sound_rabbit_mq.connection.%s', $consumerDef->name));
        $consumer = new Consumer($consumerDef->name);
        $consumer->setChannel($connection->channel());

        foreach([] as $d) {
            $consumer->consumeQueue();
        }

        return $consumer;
    }
}