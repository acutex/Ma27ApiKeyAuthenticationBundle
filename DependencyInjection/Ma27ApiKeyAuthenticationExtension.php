<?php

namespace Ma27\ApiKeyAuthenticationBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * This is the class that loads and manages your bundle configuration.
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html}
 */
class Ma27ApiKeyAuthenticationExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('ma27_api_key_authentication.model_name', $config['user']['model_name']);

        foreach (array('username', 'email', 'apiKey') as $authProperty) {
            $container->setParameter(
                sprintf('ma27_api_key_authentication.property.%s', $authProperty),
                $config['user']['properties'][$authProperty]
            );
        }

        $container->setParameter('ma27_api_key_authentication.object_manager', $config['user']['object_manager']);

        $container->setParameter(
            'ma27_api_key_authentication.property.apiKeyLength',
            intval(floor($config['user']['api_key_length'] / 2))
        );

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $this->loadPassword($container, $config['user']['properties']['password']);
        $this->loadServices($loader);
        $this->loadApiKeyPurger($container, $loader, $config['api_key_purge']);
        $this->overrideServices($container, $config['services']);
    }

    /**
     * Loads the password strategy.
     *
     * @param ContainerBuilder $container
     * @param string           $passwordConfig
     */
    private function loadPassword(ContainerBuilder $container, $passwordConfig)
    {
        $container->setParameter('ma27_api_key_authentication.property.password', $passwordConfig['property']);

        $strategyArguments = array();
        switch ($passwordConfig['strategy']) {
            case 'php55':
                $className = 'Ma27\\ApiKeyAuthenticationBundle\\Model\\Password\\PhpPasswordHasher';

                break;
            case 'crypt':
                $className = 'Ma27\\ApiKeyAuthenticationBundle\\Model\\Password\\CryptPasswordHasher';

                break;
            case 'sha512':
                $className = 'Ma27\\ApiKeyAuthenticationBundle\\Model\\Password\\Sha512PasswordHasher';

                break;
            case 'phpass':
                $className = 'Ma27\\ApiKeyAuthenticationBundle\\Model\\Password\\PHPassHasher';
                $strategyArguments[] = $passwordConfig['phpass_iteration_length'];

                break;
            default:
                throw new InvalidConfigurationException('Cannot create password config!');
        }

        $container->setDefinition(
            'ma27_api_key_authentication.password.strategy',
            new Definition($className, $strategyArguments)
        );
    }

    /**
     * Loads all internal services.
     *
     * @param Loader\YamlFileLoader $loader
     */
    private function loadServices(Loader\YamlFileLoader $loader)
    {
        foreach (array('security_key', 'authentication', 'security') as $file) {
            $loader->load(sprintf('%s.yml', $file));
        }
    }

    /**
     * Loads the purger job command into the container.
     *
     * @param ContainerBuilder      $container
     * @param Loader\YamlFileLoader $loader
     * @param string[]              $purgerConfig
     */
    private function loadApiKeyPurger(ContainerBuilder $container, Loader\YamlFileLoader $loader, array $purgerConfig)
    {
        if ($this->isConfigEnabled($container, $purgerConfig)) {
            $container->setParameter(
                'ma27_api_key_authentication.last_activation_parameter',
                $purgerConfig['last_active_property']
            );

            $loader->load('session_cleanup.yml');

            if ($purgerConfig['log_state']) {
                $container->setParameter(
                    'ma27_api_key_authentication.logger',
                    $purgerConfig['logger_service']
                );
            }
        }
    }

    /**
     * Processes the service override configuration into the container.
     *
     * @param ContainerBuilder $container
     * @param array $services
     */
    private function overrideServices(ContainerBuilder $container, array $services)
    {
        $semanticServiceReplacements = array_filter($services);
        if (!empty($semanticServiceReplacements)) {
            $serviceConfig = array(
                'auth_handler'    => 'ma27_api_key_authentication.auth_handler',
                'key_factory'     => 'ma27_api_key_authentication.key_factory',
                'password_hasher' => 'ma27_api_key_authentication.password.strategy',
            );

            foreach ($serviceConfig as $configIndex => $replaceableServiceId) {
                if (!isset($semanticServiceReplacements[$configIndex])
                    || null === $serviceId = $semanticServiceReplacements[$configIndex]
                ) {
                    continue;
                }

                $container->removeDefinition($replaceableServiceId);
                $container->setAlias($replaceableServiceId, new Alias($serviceId));
            }
        }
    }
}
