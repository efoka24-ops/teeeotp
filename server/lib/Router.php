<?php

declare(strict_types=1);

/**
 * Routeur minimal.
 *
 * Les motifs acceptent des segments nommés — `/vets/{id}/availability` — dont
 * les valeurs sont passées au gestionnaire. Les routes sont évaluées dans
 * l'ordre de déclaration : les plus spécifiques doivent donc être déclarées en
 * premier, `/vets/specialties` avant `/vets/{id}`.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, params: list<string>, handler: callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $params = [];
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];
                return '([^/]+)';
            },
            $pattern
        );

        $this->routes[] = [
            'method'  => strtoupper($method),
            'regex'   => '#^' . $regex . '$#',
            'params'  => $params,
            'handler' => $handler,
        ];
    }

    public function get(string $p, callable $h): void { $this->add('GET', $p, $h); }
    public function post(string $p, callable $h): void { $this->add('POST', $p, $h); }
    public function patch(string $p, callable $h): void { $this->add('PATCH', $p, $h); }
    public function put(string $p, callable $h): void { $this->add('PUT', $p, $h); }
    public function delete(string $p, callable $h): void { $this->add('DELETE', $p, $h); }

    public function dispatch(string $method, string $path): never
    {
        $method = strtoupper($method);
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            $pathMatched = true;

            if ($route['method'] !== $method) {
                continue;
            }

            array_shift($matches);
            $args = [];
            foreach ($route['params'] as $i => $name) {
                $args[$name] = urldecode($matches[$i] ?? '');
            }

            ($route['handler'])($args);
            // Un gestionnaire doit toujours répondre via Http : s'il ne le fait
            // pas, mieux vaut une erreur explicite qu'une réponse vide.
            Http::fail('Réponse vide du serveur.', 500);
        }

        // Distinguer « chemin inconnu » de « méthode non autorisée » aide au
        // diagnostic sans rien révéler de sensible.
        if ($pathMatched) {
            Http::fail('Méthode non autorisée.', 405, 'METHOD_NOT_ALLOWED');
        }
        Http::fail('Ressource introuvable.', 404, 'NOT_FOUND');
    }
}
