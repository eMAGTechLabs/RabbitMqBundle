<?php

namespace OldSound\RabbitMqBundle\RabbitMq;

use OldSound\RabbitMqBundle\Declarations\BatchConsumeOptions;
use OldSound\RabbitMqBundle\Declarations\ConsumeOptions;
use OldSound\RabbitMqBundle\Declarations\RpcConsumeOptions;
use OldSound\RabbitMqBundle\Event\OnConsumeEvent;
use OldSound\RabbitMqBundle\Event\OnIdleEvent;
use OldSound\RabbitMqBundle\EventDispatcherAwareTrait;
use OldSound\RabbitMqBundle\ExecuteReceiverStrategy\BatchExecuteReceiverStrategy;
use OldSound\RabbitMqBundle\ExecuteReceiverStrategy\SingleExecuteReceiverStrategy;
use OldSound\RabbitMqBundle\ReceiverExecutor\BatchReceiverExecutor;
use OldSound\RabbitMqBundle\ReceiverExecutor\ReceiverExecutorDecorator;
use OldSound\RabbitMqBundle\ReceiverExecutor\ReceiverExecutorInterface;
use OldSound\RabbitMqBundle\ReceiverExecutor\ReplyReceiverExecutor;
use OldSound\RabbitMqBundle\ReceiverExecutor\SingleReceiverExecutor;
use OldSound\RabbitMqBundle\Receiver\ReceiverInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use Symfony\Component\Serializer\SerializerInterface;

class Consumer
{
    use LoggerAwareTrait;
    use EventDispatcherAwareTrait;

    /** @var AMQPChannel */
    protected $channel;
    /** @var Consuming[] */
    protected $consumings = [];
    /** @var string[] */
    protected $consumerTags = [];
    /** @var int|null */
    protected $target;
    /** @var int */
    protected $consumed = 0;
    /** @var bool */
    protected $forceStop = false;
    /**
     * Importrant! If true - then channel can not be used from somewhere else
     * @var bool
     */
    public $multiAck = false;
    /**
     * @var \DateTime|null DateTime after which the consumer will gracefully exit. "Gracefully" means, that
     *      any currently running consumption will not be interrupted.
     */
    public $gracefulMaxExecutionDateTime;
    /** @var int Exit code used, when consumer is closed by the Graceful Max Execution Timeout feature. */
    public $gracefulMaxExecutionTimeoutExitCode = 0;
    /** @var int|null */
    public $timeoutWait;
    /** @var int */
    public $idleTimeout = 0;
    /** @var int */
    public $idleTimeoutExitCode;
    /** @var \DateTime|null */
    public $lastActivityDateTime;

    public function __construct(AMQPChannel $channel)
    {
        $this->channel = $channel;
        $this->logger = new NullLogger();
    }

    public function getChannel(): AMQPChannel
    {
        return $this->channel;
    }

    protected function setup(): Consumer
    {
        foreach($this->consumings as $index => $consuming) {
            $this->channel->basic_qos($consuming->options->qosPrefetchSize, $consuming->options->qosPrefetchCount, false);

            $options = $consuming->options;
            $consuming->consumerTag ? $consuming->consumerTag : sprintf("PHPPROCESS_%s_%s_%s", gethostname(), getmypid(), $index);
            $consumerTag = $this->channel->basic_consume(
                $options->queue,
                $consuming->consumerTag,
                $options->noLocal,
                $options->noAck,
                $options->exclusive,
                false,
                function (AMQPMessage $message) use ($consuming) {
                    $flags = $consuming->executeReceiverStrategy->onConsumeCallback($message);
                    if ($flags) {
                        $this->handleProcessMessages($flags, $consuming);
                        foreach ($messages as $message) {
                            $executeReceiverStrategy->onMessageProcessed($message);
                        }
                    }

                    $this->maybeStopConsumer();
                });

            $consuming->consumerTag = $consumerTag;
        }

        return $this;
    }

    /**
     * @param iterable|ConsumeOptions[] $queueConsumings
     */
    public function consumeQueues(iterable $queueConsumings)
    {
        foreach ($queueConsumings as $queueConsuming) {
            $this->consumeQueue($queueConsuming);
        }
    }

    private function createStrategyByOptions(ConsumeOptions $consumeOptions): ExecuteReceiverStrategyInterface
    {
        if ($consumeOptions instanceof BatchConsumeOptions) {
            return new BatchExecuteReceiverStrategy($consumeOptions->batchCount);
        }
        return new SingleExecuteReceiverStrategy();
    }

    private function createReceiverExecutorByOptions(ConsumeOptions $consumeOptions): ReceiverExecutorInterface
    {
        if ($consumeOptions instanceof BatchConsumeOptions) {
            $receiverExecutor = new BatchReceiverExecutor();
        } else if ($consumeOptions instanceof RpcConsumeOptions) {
            $receiverExecutor = new ReplyReceiverExecutor($consumeOptions);
        } else {
            $receiverExecutor = new SingleReceiverExecutor();
        }

        return $receiverExecutor;
    }

    public function consumeQueue(ConsumeOptions $consumerOptions, $receiver): Consumer
    {
        $executeReceiverStrategy = $this->createStrategyByOptions($consumerOptions);
        $receiverExecutor = $this->createReceiverExecutorByOptions($consumerOptions);
        if (!$receiverExecutor->support($receiver)) {
            throw new \InvalidArgumentException('sdfs');
        }

        $consuming = new Consuming($consumerOptions, $executeReceiverStrategy, $receiverExecutor, $receiver);
        $executeReceiverStrategy->setReceiverExecutor(
            new ReceiverExecutorDecorator($consuming, $this->logger)
        );

        $this->consumings[] = $consuming;

        return $this;
    }

    /**
     * @return ConsumeOptions[]
     */
    public function getConsumings(): array
    {
        return $this->consumings;
    }

    /**
     * Consume the message
     * @param   int     $msgAmount
     * @return  int
     *
     * @throws  AMQPTimeoutException
     */
    public function startConsume(int $msgAmount = null)
    {
        $this->target = $msgAmount;
        $this->consumed = 0;

        $this->setup();

        $this->lastActivityDateTime = new \DateTime();
        while ($this->channel->is_consuming()) {
            $this->dispatchEvent(OnConsumeEvent::NAME, new OnConsumeEvent($this));
            $this->maybeStopConsumer();

            if ($this->forceStop) {
                break;
            }
            /*
             * Be careful not to trigger ::wait() with 0 or less seconds, when
             * graceful max execution timeout is being used.
             */
            $waitTimeout = $this->chooseWaitTimeout();
            if ($this->gracefulMaxExecutionDateTime && $waitTimeout < 1) {
                return $this->gracefulMaxExecutionTimeoutExitCode;
            }

            try {
                $this->channel->wait(null, false, $waitTimeout);
                $this->lastActivityDateTime = new \DateTime();
                if ($this->forceStop) {
                    break;
                }
            } catch (AMQPTimeoutException $e) {
                foreach($this->executeReceiverStrategies as $executeReceiverStrategy) {
                    $executeReceiverStrategy->onCatchTimeout($e);
                }
                $now = new \DateTime();
                if ($this->gracefulMaxExecutionDateTime && $this->gracefulMaxExecutionDateTime <= $now) {
                    return $this->gracefulMaxExecutionTimeoutExitCode;
                }

                if ($this->idleTimeout && ($this->lastActivityDateTime->getTimestamp() + $this->idleTimeout <= $now->getTimestamp())) {
                    $idleEvent = new OnIdleEvent($this);
                    $this->dispatchEvent(OnIdleEvent::NAME, $idleEvent);

                    if ($idleEvent->isForceStop()) {
                        if (null !== $this->idleTimeoutExitCode) {
                            return $this->idleTimeoutExitCode;
                        } else {
                            throw $e;
                        }
                    }
                }
            }
        }

        return 0;
    }

    private function handleProcessMessages(array $flags, Consuming $consuming)
    {
        $ack = !array_search(fn ($reply) => $reply !== ReceiverInterface::MSG_ACK, $flags, true);
        if ($this->multiAck && count($flags) > 1 && $ack) {
            $lastDeliveryTag = array_key_last($flags);

            $this->channel->basic_ack($lastDeliveryTag, true);
            $this->consumed = $this->consumed + count($flags);
        } else {
            foreach ($flags as $deliveryTag => $flag) {
                if ($flag === ReceiverInterface::MSG_REJECT_REQUEUE) {
                    $this->channel->basic_reject($deliveryTag, true); // Reject and requeue message to RabbitMQ
                } else if ($flag === ReceiverInterface::MSG_SINGLE_NACK_REQUEUE) {
                    $this->channel->basic_nack($deliveryTag, false, true); // NACK and requeue message to RabbitMQ
                } else if ($flag === ReceiverInterface::MSG_REJECT) {
                    $this->channel->basic_reject($deliveryTag, false); // Reject and drop
                } else if ($flag !== ReceiverInterface::MSG_ACK_SENT) {
                    $this->channel->basic_ack($deliveryTag); // Remove message from queue only if callback return not false
                } else {
                    // TODO throw..
                }

                $this->consumed++;
            }
        }
    }

    protected function maybeStopConsumer()
    {
        if ($this->forceStop || ($this->target && $this->consumed == $this->target)) {
            $this->stopConsuming();
        }
    }

    public function forceStopConsumer()
    {
        $this->forceStop = true;
    }

    public function stopConsuming($immediately = false)
    {
        if (false === $immediately) {
            foreach ($this->executeReceiverStrategies as $executeReceiverStrategy) {
                $executeReceiverStrategy->onStopConsuming();
            }
        }

        foreach ($this->consumerTags as $consumerTag) {
            $this->channel->basic_cancel($consumerTag, false, true);
        }

        $this->consumerTags = [];
    }

    /**
     * @param int $secondsInTheFuture
     */
    public function setGracefulMaxExecutionDateTimeFromSecondsInTheFuture($secondsInTheFuture)
    {
        $this->gracefulMaxExecutionDateTime = new \DateTime("+{$secondsInTheFuture} seconds");
    }

    /**
     * Choose the timeout wait (in seconds) to use for the $this->getChannel()->wait() method.
     */
    private function chooseWaitTimeout(): int
    {
        if ($this->gracefulMaxExecutionDateTime) {
            $allowedExecutionSeconds = $this->gracefulMaxExecutionDateTime->getTimestamp() - time();

            /*
             * Respect the idle timeout if it's set and if it's less than
             * the remaining allowed execution.
             */
            $waitTimeout = $this->idleTimeout && $this->idleTimeout < $allowedExecutionSeconds
                ? $this->idleTimeout
                : $allowedExecutionSeconds;
        } else {
            $waitTimeout = $this->idleTimeout;
        }

        if (!is_null($this->timeoutWait) && $this->timeoutWait > 0) {
            $waitTimeout = min($waitTimeout, $this->timeoutWait);
        }
        return $waitTimeout;
    }
}
