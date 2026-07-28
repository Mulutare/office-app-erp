<?php

declare(strict_types=1);

final class Router
{
    /**
     * @var array<string, array<string, callable>>
     */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    private function add(
        string $method,
        string $path,
        callable $handler
    ): void {
        $normalizedPath = $this->normalizePath($path);

        $this->routes[$method][$normalizedPath] = $handler;
    }

    public function dispatch(string $method, string $requestUri): void
    {
        $path = parse_url($requestUri, PHP_URL_PATH);

        if (!is_string($path)) {
            $path = '/';
        }

        /*
         * Remove the shared subdirectory deployment base path.
         *
         * Both container and native web-server deployments may use it.
         * A domain rooted directly at public/ already supplies paths
         * without this prefix and therefore needs no removal.
         */
        $basePath = '/office_app/public';

        if (
            $basePath !== ''
            && str_starts_with($path, $basePath)
        ) {
            $path = substr($path, strlen($basePath));
        }

        $path = $this->normalizePath($path);
        $method = strtoupper($method);

        $handler = $this->routes[$method][$path] ?? null;

        if (!is_callable($handler)) {
            $this->notFound();

            return;
        }

        $handler();
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');

        return $path === '//' ? '/' : $path;
    }

    private function notFound(): void
    {
        http_response_code(404);

        echo '<h1>404 — Page Not Found</h1>';
        echo '<p>The requested OfficeApp page does not exist.</p>';
    }
}
