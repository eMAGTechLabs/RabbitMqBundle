<?php

namespace OldSound\RabbitMqBundle\Declarations;

use OldSound\RabbitMqBundle\Receiver\BatchReceiverInterface;
use OldSound\RabbitMqBundle\Receiver\ReceiverInterface;
use OldSound\RabbitMqBundle\Receiver\ReplyReceiverInterface;

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
    /** @var ReceiverInterface|BatchReceiverInterface|ReplyReceiverInterface */
    public $receiver;
    /** @var int */
    public $qosPrefetchCount = 0;
    /** @var int */
    public $qosPrefetchSize = 0;
    /** @var int|null */
    public $batchCount;
}