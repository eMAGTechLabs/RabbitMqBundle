<?php

namespace OldSound\RabbitMqBundle\RabbitMq;

use OldSound\RabbitMqBundle\Declarations\BindingDeclaration;
use OldSound\RabbitMqBundle\Declarations\Declarator;
use OldSound\RabbitMqBundle\Declarations\QueueConsuming;
use OldSound\RabbitMqBundle\Declarations\QueueDeclaration;
use OldSound\RabbitMqBundle\ExecuteCallbackStrategy\BatchExecuteCallbackStrategy;
use OldSound\RabbitMqBundle\RabbitMq\Exception\RpcResponseException;
use OldSound\RabbitMqBundle\Serializer\JsonMessageBodySerializer;
use OldSound\RabbitMqBundle\Serializer\MessageBodySerializerInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Serializer\Serializer;

class RpcClient implements BatchConsumerInterface
{
    /** @var AMQPChannel */
    private $channel;
    /** @var int */
    private $expiration;

    /** @var QueueDeclaration */
    private $anonRepliesQueue;
    /** @var MessageBodySerializerInterface[] */
    private $serializers;

    /** @var int */
    private $requests = 0;
    /** @var AMQPMessage[] */
    private $messages;
    private $replies = [];

    public function __construct(
        AMQPChannel $channel,
        int $expiration = 10000
    ) {
        $this->channel = $channel;
        $this->serializer = $serializer ?? new JsonMessageBodySerializer();
        $this->expiration = $expiration;
    }

    public function declareRepliesQueue($repliesQueueName = null): RpcClient
    {
        $this->anonRepliesQueue = $this->createAnonQueueDeclaration();
        $this->anonRepliesQueue->name = $repliesQueueName;
        $declarator = new Declarator($this->channel);
        [$queueName] = $declarator->declareQueues([$this->anonRepliesQueue]);
        $this->anonRepliesQueue->name = $queueName;

        return $this;
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

    public function addRequest($msgBody, $rpcQueue, MessageBodySerializerInterface $serializer = null)
    {
        if (!$this->anonRepliesQueue) {
            throw new \LogicException('no init anonRepliesQueue');
        }

        $correlationId = $this->requests;
        $this->serializers[$correlationId] = $serializer;

        $serializer = $serializer ?? new JsonMessageBodySerializer();

        $replyToQueue = $this->anonRepliesQueue->name; // 'amq.rabbitmq.reply-to';
        $msg = new AMQPMessage($serializer->serialize($msgBody, 'json'), [
            'content_type' => 'text/plain',
            'reply_to' => $replyToQueue,
            'delivery_mode' => 1, // non durable
            'expiration' => $this->expiration,
            'correlation_id' => $correlationId
        ]);

        $this->channel->basic_publish($msg, '', $rpcQueue);
        $this->requests++;
    }

    public function batchExecute(array $messages)
    {
        if ($this->messages !== null) {
            throw new \LogicException('Rpc client consming should be called once by batch count limit');
        }
        $this->messages = $messages;
    }

    /**
     * @param $name
     * @param MessageBodySerializerInterface $serializer
     * @return array|AMQPMessage[]
     */
    public function getReplies($name): array
    {
        if (0 === $this->requests) {
            throw new \LogicException('request empty');
        }

        $consumer = new Consumer($this->channel);
        $consuming = new QueueConsuming();
        $consuming->exclusive = true;
        $consuming->qosPrefetchCount = $this->requests;
        $consuming->queueName = $this->anonRepliesQueue->name;
        $consuming->callback = $this;
        $consumer->consumeQueue($consuming, new BatchExecuteCallbackStrategy($this->requests));

        try {
            $consumer->consume($this->requests);
        } finally {
            // TODO $this->getChannel()->basic_cancel($consumer_tag);
        }

        $replices = [];
        foreach($this->messages as $message) {
            /** @var AMQPMessage $message */
            if (!$message->has('correlation_id')) {
                $this->logger->error('unexpected message. rpc replies have no correlation_id ');
                continue;
            }

            $correlationId = $message->get('correlation_id');
            $serializer = $this->serializers[$correlationId];
            $reply = $serializer ? $serializer->deserialize($message->body) : $message;
            $replices[$correlationId] = $reply;
        }
        ksort($replices);
        return $replices;
    }
}
