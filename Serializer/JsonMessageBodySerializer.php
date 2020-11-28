<?php


namespace OldSound\RabbitMqBundle\Serializer;


use OldSound\RabbitMqBundle\RabbitMq\Exception\RpcResponseException;
use OldSound\RabbitMqBundle\RabbitMq\RpcReponse;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;

class JsonMessageBodySerializer implements MessageBodySerializerInterface
{
    /** @var SerializerInterface */
    private $serializer;
    /** @var string|null */
    private $deserializeType;

    public function __construct(string $deserializeType = null, SerializerInterface $serializer = null)
    {
        if ($this->deserializeType && !$this->serializer) {
            throw new \InvalidArgumentException('serializer is required if deserializerType specified');
        }
        $this->deserializeType = $deserializeType;
        $this->serializer = $serializer;
    }

    public function serialize($body): string
    {
        if ($body instanceof RpcResponseException) {
            return json_encode([
                'error_code' => $body->getCode(),
                'message' => $body->getMessage(),
            ]);
        }
        return json_encode($body);// $this->serializer->serialize($body, 'json');
    }

    public function deserialize(string $body)
    {
        if ($this->deserializeType) {
            $data = $this->serializer->deserialize($body, $this->deserializeType, 'json');
        } else {
            $data = json_decode($body, true);
            if (isset($data['error_code'])) {
                return new RpcResponseException(new RpcResponseException($data['message'], $data['error_code']));
            }
        }


        return $data;
    }

}