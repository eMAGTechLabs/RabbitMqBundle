<?php

namespace OldSound\RabbitMqBundle\RabbitMq;

use OldSound\RabbitMqBundle\Provider\QueuesProviderInterface;
use OldSound\RabbitMqBundle\RabbitMq\Exception\QueueNotFoundException;
use PhpAmqpLib\Message\AMQPMessage;

class MultipleConsumer extends Consumer
{
    /** @var array */
    protected $queues = array();

    /**
     * Queues provider
     *
     * @var QueuesProviderInterface|null
     */
    protected $queuesProvider = null;
    
    /**
     * Context the consumer runs in
     *
     * @var string
     */
    protected $context = null;

    /**
     * QueuesProvider setter
     */
    public function setQueuesProvider(QueuesProviderInterface $queuesProvider): MultipleConsumer
    {
        $this->queuesProvider = $queuesProvider;
        return $this;
    }

    public function getQueueConsumerTag(string $queue): string
    {
        return sprintf('%s-%s', $this->getConsumerTag(), $queue);
    }

    public function setQueues(array $queues): void
    {
        $this->queues = $queues;
    }
    
    public function setContext(string $context): void
    {
        $this->context = $context;
    }

    protected function setupConsumer(): void
    {
        $this->mergeQueues();

        if ($this->autoSetupFabric) {
            $this->setupFabric();
        }

        foreach ($this->queues as $name => $options) {
            //PHP 5.3 Compliant
            $currentObject = $this;

            $this->getChannel()->basic_consume($name, $this->getQueueConsumerTag($name), false, false, false, false, function (AMQPMessage $msg) use($currentObject, $name) {
                $currentObject->processQueueMessage($name, $msg);
            });
        }
    }

    protected function queueDeclare(): void
    {
        foreach ($this->queues as $name => $options) {
            list($queueName, ,) = $this->getChannel()->queue_declare(
                $name, $options['passive'],
                $options['durable'], $options['exclusive'],
                $options['auto_delete'], $options['nowait'],
                $options['arguments'], $options['ticket']);

            if (isset($options['routing_keys']) && count($options['routing_keys']) > 0) {
                foreach ($options['routing_keys'] as $routingKey) {
                    $this->queueBind($queueName, $this->exchangeOptions['name'], $routingKey, $options['arguments'] ?? []);
                }
            } else {
                $this->queueBind($queueName, $this->exchangeOptions['name'], $this->routingKey, $options['arguments'] ?? []);
            }
        }

        $this->queueDeclared = true;
    }

    /**
     * @throws \Exception
     */
    public function processQueueMessage(string $queueName, AMQPMessage $msg): void
    {
        if (!isset($this->queues[$queueName])) {
            throw new QueueNotFoundException();
        }

        $this->processMessageQueueCallback($msg, $queueName, $this->queues[$queueName]['callback']);
    }

    public function stopConsuming(): void
    {
        foreach ($this->queues as $name => $options) {
            $this->getChannel()->basic_cancel($this->getQueueConsumerTag($name), false, true);
        }
    }

    /**
     * Merges static and provided queues into one array
     */
    protected function mergeQueues(): void
    {
        if ($this->queuesProvider) {
            $this->queues = array_merge(
                $this->queues,
                $this->queuesProvider->getQueues()
            );
        }
    }
}
