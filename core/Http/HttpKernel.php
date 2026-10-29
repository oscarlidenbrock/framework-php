<?php

declare(strict_types=1);

namespace Framework\Http;

use Framework\Asset\AssetManager;
use Framework\Database\DatabaseRegistry;
use Framework\Security\AccessDeniedException;
use Framework\Security\RequestSecurityInterface;
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
        private readonly AssetManager $assets,
        private readonly ?DatabaseRegistry $databases = null,
        private readonly ?RequestSecurityInterface $security = null,
    ) {
    }

    public function handle(Request $request): Response
    {
        $this->assets->reset();
        try {
            $this->security?->begin($request);
            $response = $this->dispatch($request);
            $this->security?->finish($response, $request);
            return $response;
        } catch (\Throwable) {
            return new Response('Internal Server Error', Response::HTTP_INTERNAL_SERVER_ERROR);
        } finally {
            $this->assets->reset();
            $this->databases?->resetRequest();
            $this->security?->end();
        }
    }

    private function dispatch(Request $request): Response
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
        $access = $parameters['_access'] ?? null;
        unset($parameters['_controller'], $parameters['_access']);
        $request->attributes->add($parameters);

        if (!$this->allowed($access)) {
            return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        }

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
        } catch (AccessDeniedException) {
            return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        } catch (\Throwable) {
            return new Response('Internal Server Error', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function allowed(mixed $access): bool
    {
        if ($access === 'public') {
            return true;
        }
        if ($access === 'authenticated') {
            return $this->security?->identity() !== null;
        }
        if (is_string($access) && str_starts_with($access, 'permission:')) {
            return $this->security?->can(substr($access, strlen('permission:'))) ?? false;
        }
        return false;
    }
}
