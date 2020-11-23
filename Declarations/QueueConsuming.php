<?php

namespace OldSound\RabbitMqBundle\Declarations;

use OldSound\RabbitMqBundle\RabbitMq\BatchConsumerInterface;
use OldSound\RabbitMqBundle\RabbitMq\ConsumerInterface;

class QueueConsuming
{
    /** @var string */
    public $queueName;
    /** @var string|null */
    public $consumerTag;
    /** @var bool */
    public $noLocal = false;
    /** @var bool */
    public $noack = false;
    /** @var bool */
    public $exclusive = false;
    /** @var bool */
    public $noAck = false;
    /** @var bool */
    public $nowait = false;
    /** @var ConsumerInterface|BatchConsumerInterface */
    public $callback;

    /** @var int|null */
    public $qusPrefetchSize;
    /** @var int|null */
    public $qusPrefetchCount;
}