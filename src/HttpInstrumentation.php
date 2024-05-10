<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Lumen;

use Laravel\Lumen\Http\Request as LumenRequest;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Laravel\Lumen\Application;

class HttpInstrumentation
{
    public static function register(CachedInstrumentation $instrumentation): void
    {
        $request = LumenRequest::capture();

        hook(
            Application::class,
            'run',
            pre: static function (Application $app, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation, $request) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $instrumentation->tracer()
                    ->spanBuilder(sprintf('%s', $request?->method() ?? 'unknown'))
                    ->setSpanKind(SpanKind::KIND_SERVER)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                    ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINENO, $lineno);
                $parent = Context::getCurrent();
                if ($request) {
                    $parent = Globals::propagator()->extract($request, HeadersPropagator::instance());
                    $span = $builder
                        ->setParent($parent)
                        ->setAttribute(TraceAttributes::URL_FULL, $request->fullUrl())
                        ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $request->method())
                        ->setAttribute(TraceAttributes::HTTP_REQUEST_BODY_SIZE, $request->header('Content-Length'))
                        ->setAttribute(TraceAttributes::URL_SCHEME, $request->getScheme())
                        ->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $request->getProtocolVersion())
                        ->setAttribute(TraceAttributes::NETWORK_PEER_ADDRESS, $request->ip())
                        ->setAttribute(TraceAttributes::URL_PATH, self::httpTarget($request))
                        ->setAttribute(TraceAttributes::SERVER_ADDRESS, self::httpHostName($request))
                        ->setAttribute(TraceAttributes::SERVER_PORT, $request->getPort())
                        ->setAttribute(TraceAttributes::CLIENT_PORT, $request->server('REMOTE_PORT'))
                        ->setAttribute(TraceAttributes::USER_AGENT_ORIGINAL, $request->userAgent())
                        ->startSpan();
                    $request->attributes->set(SpanInterface::class, $span);
                } else {
                    $span = $builder->startSpan();
                }
                Context::storage()->attach($span->storeInContext($parent));

                return [$request];
            },
            post: static function (Application $app, array $params, ?Response $response, ?Throwable $exception) use($request) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());
                $span->updateName(self::getSpanName($app, $request));
                if ($exception) {
                    $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => true]);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }
                if ($response) {
                    if ($response->getStatusCode() >= 400) {
                        $span->setStatus(StatusCode::STATUS_ERROR);
                    }
                    $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());
                    $span->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $response->getProtocolVersion());
                    $span->setAttribute(TraceAttributes::HTTP_RESPONSE_BODY_SIZE, $response->headers->get('Content-Length'));

                    // Propagate server-timing header to response, if ServerTimingPropagator is present
                    if (class_exists('OpenTelemetry\Contrib\Propagation\ServerTiming\ServerTimingPropagator')) {
                        /** @phan-suppress-next-line PhanUndeclaredClassMethod */
                        $prop = new \OpenTelemetry\Contrib\Propagation\ServerTiming\ServerTimingPropagator();
                        /** @phan-suppress-next-line PhanUndeclaredClassMethod */
                        $prop->inject($response, ResponsePropagationSetter::instance(), $scope->context());
                    }

                    // Propagate traceresponse header to response, if TraceResponsePropagator is present
                    if (class_exists('OpenTelemetry\Contrib\Propagation\TraceResponse\TraceResponsePropagator')) {
                        /** @phan-suppress-next-line PhanUndeclaredClassMethod */
                        $prop = new \OpenTelemetry\Contrib\Propagation\TraceResponse\TraceResponsePropagator();
                        /** @phan-suppress-next-line PhanUndeclaredClassMethod */
                        $prop->inject($response, ResponsePropagationSetter::instance(), $scope->context());
                    }
                }

                $span->end();
            }
        );
    }

    private static function getRouteName(Application $app, LumenRequest $request): string {
        if (is_array($route)) {
            if ($route[1]['as'] ?? false) {
                // Try named routes
                $routesByName = $app->router->namedRoutes;
                return $routesByName[$route[1]['as']] ??  $route[1]['as'];
                return $route[1]['as'];
            }

            if ($route[1]['uses'] ?? false) {
                //Try the assigned controller action
                return  $route[1]['uses'];
            }
        }

        return 'index.php';
    }
    private static function getSpanName(LumenRequest $request): string
    {
        if (!$request) {
            return 'unknown';
        }

        return sprintf ('%s - %s', $request->getMethod(), self::getRouteName($request));
    }

    private static function httpTarget(SymfonyRequest $request): string
    {
        $query = $request->getQueryString();
        $question = $request->getBaseUrl() . $request->getPathInfo() === '/' ? '/?' : '?';

        return $query ? $request->path() . $question . $query : $request->path();
    }

    private static function httpHostName(SymfonyRequest $request): string
    {
        if (method_exists($request, 'host')) {
            return $request->host();
        }
        if (method_exists($request, 'getHost')) {
            return $request->getHost();
        }

        return '';
    }
}
