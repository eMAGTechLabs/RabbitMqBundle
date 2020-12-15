<?php

namespace OldSound\RabbitMqBundle\RabbitMq;

use OldSound\RabbitMqBundle\Declarations\BatchConsumeOptions;
use OldSound\RabbitMqBundle\Declarations\ConsumeOptions;
use OldSound\RabbitMqBundle\Declarations\RpcConsumeOptions;
use OldSound\RabbitMqBundle\ExecuteReceiverStrategy\BatchExecuteReceiverStrategy;
use OldSound\RabbitMqBundle\ExecuteReceiverStrategy\ExecuteReceiverStrategyInterface;
use OldSound\RabbitMqBundle\ExecuteReceiverStrategy\MessagesProcessorInterface;
use OldSound\RabbitMqBundle\ExecuteReceiverStrategy\SingleExecuteReceiverStrategy;
use OldSound\RabbitMqBundle\ReceiverExecutor\BatchReceiverExecutor;
use OldSound\RabbitMqBundle\ReceiverExecutor\ReceiverExecutorInterface;
use OldSound\RabbitMqBundle\ReceiverExecutor\SingleReceiverExecutor;
use OldSound\RabbitMqBundle\Receiver\BatchReceiverInterface;
use OldSound\RabbitMqBundle\Receiver\ReceiverInterface;
use OldSound\RabbitMqBundle\Receiver\ReplyReceiverInterface;

class Consuming
{
    /**
     * @var ConsumeOptions
     * @var BatchConsumeOptions
     * @var RpcConsumeOptions
     * @var mixed
     */
    public $options;
    /**
     * @var ExecuteReceiverStrategyInterface
     * @see SingleExecuteReceiverStrategy
     * @see BatchExecuteReceiverStrategy
     */
    public $executeReceiverStrategy;
    /**
     * @var ReceiverExecutorInterface
     * @see SingleReceiverExecutor
     * @see BatchReceiverExecutor
     */
    public $receiverExecutor;
    /**
     * @var ReceiverInterface
     * @var BatchReceiverInterface
     * @var ReplyReceiverInterface
     * @var mixed
     */
    public $receiver;

    /** @var int|string|null */
    public $consumerTag;

    public function __construct($options, ExecuteReceiverStrategyInterface $executeReceiverStrategy, ReceiverExecutorInterface $receiverExecutor, $receiver)
    {
        $this->options = $options;
        $this->executeReceiverStrategy = $executeReceiverStrategy;
        $this->receiverExecutor = $receiverExecutor;
        $this->receiver = $receiver;
    }
}