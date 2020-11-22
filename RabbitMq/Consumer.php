<?php

namespace OldSound\RabbitMqBundle\RabbitMq;

use http\Exception\InvalidArgumentException;
use OldSound\RabbitMqBundle\Declarations\QueueConsuming;
use OldSound\RabbitMqBundle\Event\AfterProcessingMessageEvent;
use OldSound\RabbitMqBundle\Event\AMQPEvent;
use OldSound\RabbitMqBundle\Event\BeforeProcessingMessageEvent;
use OldSound\RabbitMqBundle\Event\OnConsumeEvent;
use OldSound\RabbitMqBundle\Event\OnIdleEvent;
use OldSound\RabbitMqBundle\EventDispatcherAwareTrait;
use OldSound\RabbitMqBundle\MemoryChecker\MemoryConsumptionChecker;
use OldSound\RabbitMqBundle\MemoryChecker\NativeMemoryUsageProvider;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;

class Consumer
{
    use LoggerAwareTrait;
    use EventDispatcherAwareTrait;
    
    /** @var string */
    public $name;
    /** @var AMQPChannel */
    protected $channel;
    /** @var QueueConsuming[] */
    protected $queueConsumings = [];
    /** @var string[] */
    protected $consumerTags = [];
    /** @var array */
    protected $basicProperties = [
        'content_type' => 'text/plain',
        'delivery_mode' => 2
    ];
    /** @var bool */
    protected $enabledPcntlSignals = false;
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

    
    public function __construct(string $name, AMQPChannel $channel)
    {
        $this->name = $name;
        $this->channel = $channel;
        $this->logger = new NullLogger();
    }
    
    public function getName(): string
    {
        return $this->name;
    }

    public function getChannel(): AMQPChannel
    {
        return $this->channel;
    }
    
    protected function setup()
    {
        foreach($this->queueConsumings as $index => $queueConsuming) {
            $this->consumerTags[] = $this->channel->basic_consume(
                $queueConsuming->queueName,
                $queueConsuming->consumerTag ?
                    $queueConsuming->consumerTag :
                    sprintf("PHPPROCESS_%s_%s_%s", gethostname(), getmypid(), $index),
                $queueConsuming->noLocal,
                $queueConsuming->noAck,
                $queueConsuming->exclusive,
                $queueConsuming->nowait,
                function (AMQPMessage $msg) use ($queueConsuming) {
                    $this->consumeCallback($msg, $queueConsuming);
                });
        }
    }

    protected function consumeCallback(AMQPMessage $message, QueueConsuming $queueConsuming)
    {
        $this->processMessages([$message], [$queueConsuming]);
    }
    
    public function consumeQueue(QueueConsuming $queueConsuming)
    {
        $this->queueConsumings[] = $queueConsuming;
    }

    /**
     * @return QueueConsuming[]
     */
    public function getQueueConsumings(): array
    {
        return $this->queueConsumings;
    }

    protected function preConsume()
    {
        $this->dispatchEvent(OnConsumeEvent::NAME, new OnConsumeEvent($this));
        $this->maybeStopConsumer();
    }

    protected function catchTimeout(AMQPTimeoutException $e)
    {
    }

    /**
     * Consume the message
     * @param   int     $msgAmount
     * @return  int
     *
     * @throws  AMQPTimeoutException
     */
    public function consume(int $msgAmount = null)
    {
        $this->target = $msgAmount;
        $this->consumed = 0;
        
        $this->setup();
        
        $this->lastActivityDateTime = new \DateTime();
        while ($this->channel->is_consuming()) {
            $this->preConsume();

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
                $this->catchTimeout($e);
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

    /**
     * @param AMQPMessage[] $messages
     * @param QueueConsuming[] $queueConsumings
     */
    protected function processMessages(array $messages, array $queueConsumings)
    {
        foreach ($messages as $index => $message) {
            $this->dispatchEvent(BeforeProcessingMfessageEvent::NAME,
                new BeforeProcessingMessageEvent($this, $message, $queueConsumings[$index])
            );
        }

        $logAmqpContent = [
            'consumer' => $this->name,
            'queue' => $queueConsuming->queueName,
        ] + (
            count($messages) === 1 ? ['message' => $messages[0]] : ['messages' => $messages]
        );

        try {
            $processFlags = call_user_func($queueConsuming->callback, count($messages) === 1 ? $messages[0] : $messages);
            $processFlags = count($messages) === 1 ? [$messages[0]->getDeliveryTag() => $processFlag] : $processFlags;

            if (!$queueConsuming->noAck) {
                $this->handleProcessMessages($messages, $processFlags);

                foreach ($messages as $index => $message) {
                    $channel = $message->delivery_info['channel'];
                    $deliveryTag = $message->getDeliveryTag();
                    $precessFlag = $processFlags[$message->getDeliveryTag()];
                    $this->handleProcessFlag($channel, $deliveryTag, $precessFlag);
                    $this->consumed++;
                }
            }

            $this->logger->debug(
                count($messages) === 1 ?
                    'Queue message processed' :
                    'Queue messages processed', ['amqp' => $logAmqpContent + ['return_code' => $processFlag]]);

            $this->dispatchEvent(
                AfterProcessingMessageEvent::NAME,
                new AfterProcessingMessageEvent($this, $msg)
            );
            $this->maybeStopConsumer();
        } catch (Exception\StopConsumerException $e) {
            $this->logger->info('Consumer requested restart', [
                'amqp' => $logAmqpContent + ['stacktrace' => $e->getTraceAsString()]
            ]);
            if (!$queueConsuming->noAck) {
                $this->handleProcessMessage($msg, $e->getHandleCode());
            }
            $this->stopConsuming();
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage(), [
                'amqp' => $logAmqpContent + ['stacktrace' => $e->getTraceAsString()]
            ]);
            throw $e;
        }
    }

    private function analyzeProcessFlags($messages, $processFlags = null): array
    {
        if (is_array($processFlags)) {
            if (count($processFlags) !== count($this->messages)) {
                throw new AMQPRuntimeException(
                    'Method batchExecute() should return an array with elements equal with the number of messages processed'
                );
            }

            return $processFlags;
        }

        $response = [];
        foreach ($messages as $deliveryTag => $message) {
            $response[$deliveryTag] = $processFlags;
        }

        return $response;
    }

    /**
     * @param AMQPMessage[] $messages
     * @param array|int $processFlags
     * @see ConsumerInterface
     */
    protected function handleProcessMessages($messages, $processFlags)
    {
        if ($this->multiAck && count($messages) > 1 && $processFlags === ConsumerInterface::MSG_ACK) {
            // all messages have same channel
            $channels = array_map(function ($message) {
                return $message->getChannel();
            }, $messages);
            if (count($channels) !== array_unique($channels)) {
                throw new InvalidArgumentException('Messages can not be processed as multi ack with different channels');
            }
            $this->channel->basic_ack(last($deliveryTag), true);
            return;
        }
        foreach ($this->analyzeProcessFlags($messages, $processFlags) as $deliveryTag => $processFlag) {
            $message = isset($messages[$deliveryTag]) ? $messages[$deliveryTag] : null;
            if (null === $message) {
                throw new AMQPRuntimeException(sprintf('Unknown delivery_tag %d!', $deliveryTag));
            }

            $this->handleProcessFlag($message->getChannel(), $deliveryTag, $processFlag);
        }
    }

    private function handleProcessFlag(AMQPChannel $channel, $deliveryTag, $processFlag)
    {
        if ($processFlag === ConsumerInterface::MSG_REJECT_REQUEUE || false === $processFlag) {
            $channel->basic_reject($deliveryTag, true); // Reject and requeue message to RabbitMQ
        } else if ($processFlag === ConsumerInterface::MSG_SINGLE_NACK_REQUEUE) {
            $channel->basic_nack($deliveryTag, false, true); // NACK and requeue message to RabbitMQ
        } else if ($processFlag === ConsumerInterface::MSG_REJECT) {
            $channel->basic_reject($deliveryTag, false); // Reject and drop
        } else if ($processFlag !== ConsumerInterface::MSG_ACK_SENT) {
            $channel->basic_ack($deliveryTag); // Remove message from queue only if callback return not false
        }
    }

    protected function maybeStopConsumer()
    {
        if ($this->enabledPcntlSignals) {
            pcntl_signal_dispatch();
        }

        if ($this->forceStop || ($this->target && $this->consumed == $this->target)) {
            $this->stopConsuming();
        }
    }

    public function enablePcntlSignals()
    {
        $this->enabledPcntlSignals = true;
    }

    public function forceStopConsumer()
    {
        $this->forceStop = true;
    }

    public function stopConsuming()
    {
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
