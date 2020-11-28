<?php

namespace OldSound\RabbitMqBundle\DependencyInjection\Compiler;

use OldSound\RabbitMqBundle\Logger\ExtraContextLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

class RegisterPartsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        $taggedConsumers = $container->findTaggedServiceIds('old_sound_rabbit_mq.consumer');

        foreach ($taggedConsumers as $id => $tag) {
            $definition = $container->getDefinition($id);

        }

        $taggedConsumers = $container->findTaggedServiceIds('old_sound_rabbit_mq.consumer');
        foreach ($taggedConsumers as $id => $tags) {
            $consumerName = $tags[0]['name'];
            $originLogger = $id . '.logger';

            if ($container->hasDefinition($originLogger)) {
                $consumerDef = $container->getDefinition($id);
                $consumerDef->removeMethodCall('setLogger');
                $loggerDef = new Definition(ExtraContextLogger::class, [new Reference($originLogger), ['consumer' => $consumerName]]);
                $consumerDef->addMethodCall('setLogger', [$loggerDef]);
            }
        }

        $tags = [
            'old_sound_rabbit_mq.base_amqp',
            'old_sound_rabbit_mq.binding',
            'old_sound_rabbit_mq.producer',
            'old_sound_rabbit_mq.consumer',
            'old_sound_rabbit_mq.multi_consumer',
            'old_sound_rabbit_mq.anon_consumer',
            'old_sound_rabbit_mq.batch_consumer',
            'old_sound_rabbit_mq.rpc_client',
            'old_sound_rabbit_mq.rpc_server',
        ];
    }
}
