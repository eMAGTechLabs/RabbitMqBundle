<?php

namespace OldSound\RabbitMqBundle\Declarations;

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
    /** @var callable */
    public $callback;
}