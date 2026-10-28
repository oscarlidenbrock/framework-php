<?php

declare(strict_types=1);

namespace Framework\Http;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Matcher\UrlMatcher;

final class HttpKernel
{
    public function __construct(
        private readonly RouteCollection $routes,
        private readonly ContainerInterface $container,
    ) {
    }

    public function handle(Request $request): Response
    {
        $context = new RequestContext();
        $context->fromRequest($request);

        try {
            $parameters = (new UrlMatcher($this->routes, $context))->match($request->getPathInfo());
        } catch (ResourceNotFoundException) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND);
        } catch (MethodNotAllowedException $exception) {
            return new Response('Method Not Allowed', Response::HTTP_METHOD_NOT_ALLOWED, ['Allow' => implode(', ', $exception->getAllowedMethods())]);
        }

        $controllerId = $parameters['_controller'];
        unset($parameters['_controller']);
        $request->attributes->add($parameters);

        try {
            $controller = $this->container->get($controllerId);
            if (!is_callable($controller)) {
                throw new \LogicException("Controller service is not callable: {$controllerId}");
            }
            $response = $controller($request);
            if (!$response instanceof Response) {
                throw new \LogicException("Controller {$controllerId} did not return a Response.");
            }

            return $response;
        } catch (\Throwable) {
            return new Response('Internal Server Error', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
