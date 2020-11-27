<?php

namespace OldSound\RabbitMqBundle\RabbitMq;

use OldSound\RabbitMqBundle\Declarations\BindingDeclaration;
use OldSound\RabbitMqBundle\Declarations\Declarator;
use OldSound\RabbitMqBundle\Declarations\QueueConsuming;
use OldSound\RabbitMqBundle\Declarations\QueueDeclaration;
use OldSound\RabbitMqBundle\ExecuteCallbackStrategy\BatchExecuteCallbackStrategy;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Serializer\Serializer;

class RpcClient
{
    /** @var QueueDeclaration */
    private $anonRepliesQueue;
    /** @var string */
    private $name;
    /** @var AMQPChannel */
    private $channel;
    /** @var Serializer */
    private $serializer;
    /** @var string|null */
    private $repliesQueueName;
    /** @var int */
    private $expiration;

    private $requests = 0;
    private $replies = [];

    public function __construct(
        string $name,
        AMQPChannel $channel,
        Serializer $serializer,
        string $repliesQueueName = null,
        int $expiration = 10000
    ) {
        $this->name = $name;
        $this->channel = $channel;
        $this->serializer = $serializer;
        $this->repliesQueueName = $repliesQueueName;
        $this->expiration = $expiration;
    }

    public function setup(): RpcClient
    {
        $this->anonRepliesQueue = $this->createAnonQueueDeclaration();
        $this->anonRepliesQueue->name = $this->repliesQueueName;
        $declarator = new Declarator($this->channel);
        [$queueName] = $declarator->declareQueues([$this->anonRepliesQueue]);
        $this->anonRepliesQueue->name = $queueName;

        return $this;
    }

    public function addRequest($msgBody, $rpcQueue)
    {
        if (!$this->anonRepliesQueue) {
            throw new \LogicException('no init anonRepliesQueue');
        }

        $replyToQueue = $this->anonRepliesQueue->name; // 'amq.rabbitmq.reply-to';
        $msg = new AMQPMessage($this->serializer->serialize($msgBody, 'json'), [
            'content_type' => 'text/plain',
            'reply_to' => $replyToQueue,
            'delivery_mode' => 1, // non durable
            'expiration' => $this->expiration,
            'correlation_id' => $this->requests
        ]);

        $this->channel->basic_publish($msg, '', $rpcQueue);
        $this->requests++;
    }

    // TODO public move
    private function createAnonQueueDeclaration(): QueueDeclaration
    {
        $anonQueueDeclaration = new QueueDeclaration();
        $anonQueueDeclaration->passive = false;
        $anonQueueDeclaration->durable = false;
        $anonQueueDeclaration->exclusive = true;
        $anonQueueDeclaration->autoDelete = true;
        $anonQueueDeclaration->nowait = false;

        return $anonQueueDeclaration;
    }

    public function getReplies($name, $type): array
    {
        if (0 === $this->requests) {
            throw new \LogicException('request empty');
        }

        $consumer = new Consumer('rpc_replies_' . $this->name, $this->channel);
        $consuming = new QueueConsuming();
        $consuming->exclusive = true;
        $consuming->qosPrefetchCount = $this->requests;
        $consuming->queueName = $this->anonRepliesQueue->name;
        $consuming->callback = new class() implements BatchConsumerInterface {
            /** @var AMQPMessage[] */
            public $messages;
            public function batchExecute(array $messages)
            {
                if ($this->messages !== null) {
                    throw new \LogicException('Rpc client consming should be called once by batch count limit');
                }
                $this->messages = $messages;
            }
        };
        $consumer->consumeQueue($consuming, new BatchExecuteCallbackStrategy($this->requests));

        try {
            $consumer->consume($this->requests);
        } finally {
            // TODO $this->getChannel()->basic_cancel($consumer_tag);
        }

        $replices = [];
        foreach($consuming->callback->messages as $message) {
            if (null === $message->get('correlation_id')) {
                $this->logger->error('unexpected message. rpc replies have no correlation_id ');
                continue;
            }
            $replices[$message->get('correlation_id')] = $this->serializer->deserialize($message->body, $type, 'json');
        }
        ksort($replices);
        return $replices;
    }
}
