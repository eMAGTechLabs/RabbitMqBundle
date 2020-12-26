<?php

namespace OldSound\RabbitMqBundle\RabbitMq;

use OldSound\RabbitMqBundle\Declarations\BatchConsumeOptions;
use OldSound\RabbitMqBundle\Declarations\ConsumeOptions;
use OldSound\RabbitMqBundle\Declarations\ConsumerDef;
use OldSound\RabbitMqBundle\Declarations\RpcConsumeOptions;
use OldSound\RabbitMqBundle\Event\OnConsumeEvent;
use OldSound\RabbitMqBundle\Event\OnIdleEvent;
use OldSound\RabbitMqBundle\EventDispatcherAwareTrait;
use OldSound\RabbitMqBundle\ExecuteReceiverStrategy\BatchExecuteReceiverStrategy;
use OldSound\RabbitMqBundle\ExecuteReceiverStrategy\ExecuteReceiverStrategyInterface;
use OldSound\RabbitMqBundle\ExecuteReceiverStrategy\SingleExecuteReceiverStrategy;
use OldSound\RabbitMqBundle\ReceiverExecutor\BatchReceiverExecutor;
use OldSound\RabbitMqBundle\ReceiverExecutor\ReceiverExecutorDecorator;
use OldSound\RabbitMqBundle\Receiver\ReceiverInterface;
use OldSound\RabbitMqBundle\ReceiverExecutor\ReceiverExecutorInterface;
use OldSound\RabbitMqBundle\ReceiverExecutor\ReplyReceiverExecutor;
use OldSound\RabbitMqBundle\ReceiverExecutor\SingleReceiverExecutor;
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

    /** @var ConsumerDef */
    protected $consumerDef;
    /** @var \AMQPChannel */
    protected $channel;

    /** @var callable[] */
    protected $receivers;
    /** @var ExecuteReceiverStrategyInterface[] */
    protected $executeReceiverStrategies;

    /** @var int|null */
    protected $target;
    /** @var int */
    protected $consumed = 0;
    /** @var bool */
    protected $forceStop = false;
    /**
     * Important! If true - then channel can not be used from somewhere else
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

    public function __construct(ConsumerDef $consumerDef)
    {
        $this->consumerDef = $consumerDef;
        $this->logger = new NullLogger();
    }

    protected function setup(): Consumer
    {
        foreach($this->consumerDef->consumeOptions as $index => $options) {
            $this->channel->basic_qos($options->qosPrefetchSize, $options->qosPrefetchCount, false);

            $options->consumerTag = $options->consumerTag ?? sprintf("PHPPROCESS_%s_%s_%s", gethostname(), getmypid(), $index);

            $executeReceiverStrategy = $options instanceof BatchConsumeOptions ?
                new BatchExecuteReceiverStrategy($options->batchCount) :
                new SingleExecuteReceiverStrategy();

            $executeReceiverStrategies[$index] = $executeReceiverStrategy;

            // TODO chining
            $receiverExecutor = new ReceiverExecutorDecorator($this->createExecutor($options), $this->consumerDef, $options);
            $receiverExecutor->setLogger($this->logger);
            if ($this->eventDispatcher) {
                $receiverExecutor->setEventDispatcher($this->eventDispatcher);
            }
            $executeReceiverStrategy->setReceiver($options->receiver, $receiverExecutor);

            $consumerTag = $this->channel->basic_consume(
                $options->queue,
                $options->consumerTag,
                $options->noLocal,
                $options->noAck,
                $options->exclusive,
                false,
                function (AMQPMessage $message) use ($executeReceiverStrategy) {
                    $flags = $executeReceiverStrategy->onConsumeCallback($message);
                    if (null !== $flags) {
                        $this->handleProcessMessages($flags);
                        $executeReceiverStrategy->onMessageProcessed($message);
                    }

                    $this->maybeStopConsumer();
                });

            $options->consumerTag = $consumerTag;
        }

        return $this;
    }

    private function createExecutor(ConsumeOptions $options): ReceiverExecutorInterface
    {
        if ($options instanceof BatchConsumeOptions) {
            return new BatchReceiverExecutor();
        } else if ($options instanceof RpcConsumeOptions) {
            return new ReplyReceiverExecutor($options);
        } else {
            return new SingleReceiverExecutor();
        }
    }

    /**
     * @throws  AMQPTimeoutException
     */
    public function startConsume(int $msgAmount = null): int
    {
        $this->target = $msgAmount;
        $this->consumed = 0;

        $this->channel = AMQPConnectionFactory::getChannelFromConnection($this->consumerDef->connection);
        $this->setup();
        $this->lastActivityDateTime = new \DateTime();
        while ($this->channel->is_consuming()) {
            $event = new OnConsumeEvent();
            $this->dispatchEvent($event, OnConsumeEvent::NAME);
            if ($event->isForceStop()) {
                break;
            }
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
                $now = new \DateTime();
                if ($this->gracefulMaxExecutionDateTime && $this->gracefulMaxExecutionDateTime <= $now) {
                    return $this->gracefulMaxExecutionTimeoutExitCode;
                }

                if ($this->idleTimeout && ($this->lastActivityDateTime->getTimestamp() + $this->idleTimeout <= $now->getTimestamp())) {
                    $idleEvent = new OnIdleEvent();
                    $this->dispatchEvent($idleEvent, OnIdleEvent::NAME);

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

    private function handleProcessMessages(array $flags)
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

        foreach ($this->consumerDef->consumeOptions as $options) {
            $this->channel->basic_cancel($options->consumerTag, false, true);
            $options->consumerTag = null;
        }
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
