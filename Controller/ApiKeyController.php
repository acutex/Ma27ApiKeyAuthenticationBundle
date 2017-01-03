<?php

namespace Ma27\ApiKeyAuthenticationBundle\Controller;

use Ma27\ApiKeyAuthenticationBundle\Event\OnAssembleResponseEvent;
use Ma27\ApiKeyAuthenticationBundle\Event\OnCredentialExceptionThrownEvent;
use Ma27\ApiKeyAuthenticationBundle\Exception\CredentialException;
use Ma27\ApiKeyAuthenticationBundle\Ma27ApiKeyAuthenticationEvents;
use Ma27\ApiKeyAuthenticationBundle\Service\Mapping\ClassMetadata;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Controller which is responsible for the authentication routes.
 */
class ApiKeyController extends Controller
{
    /**
     * Requests an api key.
     *
     * @param Request $request
     *
     * @throws HttpException If the login fails.
     *
     * @return JsonResponse
     */
    public function requestApiKeyAction(Request $request)
    {
        /** @var ClassMetadata $metadata */
        $metadata = $this->get('ma27_api_key_authentication.class_metadata');
        /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher */
        $dispatcher = $this->get('event_dispatcher');

        $credentials = array();
        if ($request->request->has('login')) {
            $credentials[$metadata->getPropertyName(ClassMetadata::LOGIN_PROPERTY)] = $request->request->get('login');
        }
        if ($request->request->has('password')) {
            $credentials[$metadata->getPropertyName(ClassMetadata::PASSWORD_PROPERTY)] = $request->request->get('password');
        }

        [$user, $exception] = $this->processAuthentication($credentials);

        /** @var OnAssembleResponseEvent $result */
        $result = $dispatcher->dispatch(
            Ma27ApiKeyAuthenticationEvents::ASSEMBLE_RESPONSE,
            new OnAssembleResponseEvent($user, $exception)
        );

        if (!$response = $result->getResponse()) {
            throw new HttpException(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                'Cannot assemble the response!',
                $exception
            );
        }

        return $response;
    }

    /**
     * Removes an api key.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function removeSessionAction(Request $request)
    {
        /** @var \Ma27\ApiKeyAuthenticationBundle\Service\Auth\AuthenticationHandlerInterface $authenticationHandler */
        $authenticationHandler = $this->get('ma27_api_key_authentication.auth_handler');
        /** @var \Doctrine\Common\Persistence\ObjectManager $om */
        $om = $this->get($this->container->getParameter('ma27_api_key_authentication.object_manager'));
        /** @var ClassMetadata $metadata */
        $metadata = $this->get('ma27_api_key_authentication.class_metadata');

        if (!$header = (string) $request->headers->get($this->container->getParameter('ma27_api_key_authentication.key_header'))) {
            return new JsonResponse(array('message' => 'Missing api key header!'), 400);
        }

        $repository = $om->getRepository($this->container->getParameter('ma27_api_key_authentication.model_name'));
        $user = $repository->findOneBy(array($metadata->getPropertyName(ClassMetadata::API_KEY_PROPERTY) => (string) $header));

        $authenticationHandler->removeSession($user);

        return new JsonResponse(array(), 204);
    }

    /**
     * Internal utility to handle the authentication process based on the credentials.
     *
     * @param array $credentials
     *
     * @return array
     */
    private function processAuthentication(array $credentials)
    {
        /** @var \Ma27\ApiKeyAuthenticationBundle\Service\Auth\AuthenticationHandlerInterface $authenticationHandler */
        $authenticationHandler = $this->get('ma27_api_key_authentication.auth_handler');
        /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher */
        $dispatcher = $this->get('event_dispatcher');

        try {
            $user = $authenticationHandler->authenticate($credentials);
        } catch (CredentialException $ex) {
            $userOrNull = $user ?? null;
            $dispatcher->dispatch(
                Ma27ApiKeyAuthenticationEvents::CREDENTIAL_EXCEPTION_THROWN,
                new OnCredentialExceptionThrownEvent($ex, $userOrNull)
            );

            return [$userOrNull, $ex];
        }

        return [$user, null];
    }
}
