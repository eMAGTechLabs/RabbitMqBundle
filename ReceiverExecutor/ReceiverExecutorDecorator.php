<?php

namespace OldSound\RabbitMqBundle\ReceiverExecutor;

use OldSound\RabbitMqBundle\Declarations\BatchConsumeOptions;
use OldSound\RabbitMqBundle\Declarations\ConsumeOptions;
use OldSound\RabbitMqBundle\Declarations\RpcConsumeOptions;
use OldSound\RabbitMqBundle\Event\AfterProcessingMessagesEvent;
use OldSound\RabbitMqBundle\Event\BeforeProcessingMessagesEvent;
use OldSound\RabbitMqBundle\EventDispatcherAwareTrait;
use OldSound\RabbitMqBundle\Logger\ExtraContextLogger;
use Psr\Log\LoggerInterface;
use PhpAmqpLib\Message\AMQPMessage;

class ReceiverExecutorDecorator implements ReceiverExecutorInterface
{
    use EventDispatcherAwareTrait;

    /** @var ConsumeOptions */
    private $options;

    /** @var ReceiverExecutorInterface */
    private $receiverExecutor;

    public function __construct(ConsumeOptions $options, LoggerInterface $logger)
    {
        $this->options = $options;
        $this->logger = $logger;
        $this->receiverExecutor = $this->createExecutor($options);
    }

    /**
     * @param AMQPMessage[] $messages
     * @param callable $receiver
     * @return array
     * @throws \Throwable
     */
    public function execute(array $messages, callable $receiver): array
    {
        $logger = new ExtraContextLogger($this->logger, ['amqp' => $this->createLoggerExtraContext($messages)]);

        $event = new BeforeProcessingMessagesEvent($messages, $this->options);
        $this->dispatchEvent(BeforeProcessingMessagesEvent::NAME, $event);
        if ($event->isForceStop()) {
            throw new StopConsumerException();
        }

        try {
            $flags = $this->receiverExecutor->execute($this->convertMessagesArgument($messages), $receiver);
        } catch (Exception\StopConsumerException $e) {
            $logger->info('Consumer requested stop', [
                'exception' => $e
            ]);

            throw $e;
        } catch (\Throwable $e) {
            $logger->error('Throw exception while process messages', [
                'exception' => $e
            ]);
            throw $e;
        }


        $logger->info('Queue messages processed', ['amqp' => ['flags' => $flags]]);
        $event = new AfterProcessingMessagesEvent($messages); // TODO add flag code
        $this->dispatchEvent(AfterProcessingMessagesEvent::NAME, $event);
        if ($event->isForceStop()) {
            throw new StopConsumerException();
        }

        return $flags;
    }

    /**
     * @param AMQPMessage[] $messages
     */
    private function convertMessagesArgument(array $messages): array
    {
        // TODO

        return $messages;
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

    private function createLoggerExtraContext(array $messages): array
    {
        return [
            'queue' => $this->options->queue,
            'messages' => $messages
        ];
    }
}