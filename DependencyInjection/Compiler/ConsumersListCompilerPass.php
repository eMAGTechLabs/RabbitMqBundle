<?php

namespace OldSound\RabbitMqBundle\DependencyInjection\Compiler;

use OldSound\RabbitMqBundle\Logger\ExtraContextLogger;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

class ConsumersListCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        $taggedConsumers = $container->findTaggedServiceIds('old_sound_rabbit_mq.consumer');
        $consumerNames = array_map(fn ($tags) => $tags[0]['consumer'], $taggedConsumers);
        $container->setParameter('old_sound_rabbit_mq.allowed_consumer_names', $consumerNames);
    }
}