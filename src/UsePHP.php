<?php

declare(strict_types=1);

namespace Polidog\UsePhp;

use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\ComponentInterface;
use Polidog\UsePhp\Component\ComponentRegistry;
use Polidog\UsePhp\Runtime\Action;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\Renderer;

/**
 * Main application class for usePHP.
 */
class UsePHP
{
    private static ?self $instance = null;

    private ComponentRegistry $registry;
    private ?string $currentComponent = null;
    private string $jsPath = '/usephp.js';
    private string $layout = 'default';

    /** @var array<string, callable> */
    private array $layouts = [];

    private function __construct()
    {
        $this->registry = new ComponentRegistry();
        $this->registerDefaultLayout();
    }

    /**
     * Get the singleton instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register a component class.
     *
     * @param class-string<ComponentInterface> $className
     */
    public static function register(string $className): self
    {
        $instance = self::getInstance();
        $instance->registry->register($className);
        return $instance;
    }

    /**
     * Auto-load components from a directory.
     */
    public static function autoload(string $directory, string $namespace): self
    {
        $instance = self::getInstance();
        $instance->registry->autoload($directory, $namespace);
        return $instance;
    }

    /**
     * Set the path to usephp.js
     */
    public static function setJsPath(string $path): self
    {
        $instance = self::getInstance();
        $instance->jsPath = $path;
        return $instance;
    }

    /**
     * Register a layout.
     *
     * @param callable(string $content, string $title): string $callback
     */
    public static function layout(string $name, callable $callback): self
    {
        $instance = self::getInstance();
        $instance->layouts[$name] = $callback;
        return $instance;
    }

    /**
     * Set the default layout to use.
     */
    public static function useLayout(string $name): self
    {
        $instance = self::getInstance();
        $instance->layout = $name;
        return $instance;
    }

    /**
     * Run the application.
     */
    public static function run(?string $componentName = null): void
    {
        $instance = self::getInstance();
        $instance->handleRequest($componentName);
    }

    /**
     * Render a component and return HTML (without layout).
     */
    public static function renderComponent(string $componentName): string
    {
        $instance = self::getInstance();
        return $instance->doRenderComponent($componentName);
    }

    /**
     * Handle the incoming request.
     */
    private function handleRequest(?string $componentName): void
    {
        // Determine which component to render
        $componentName = $componentName ?? $this->resolveComponentFromRequest();

        if ($componentName === null) {
            http_response_code(404);
            echo 'Component not found';
            return;
        }

        if (!$this->registry->has($componentName)) {
            http_response_code(404);
            echo "Component not found: {$componentName}";
            return;
        }

        $this->currentComponent = $componentName;

        // Handle AJAX action request
        if ($this->isActionRequest()) {
            $this->handleAction($componentName);
            return;
        }

        // Render the full page
        $this->renderPage($componentName);
    }

    /**
     * Resolve component name from the request.
     */
    private function resolveComponentFromRequest(): ?string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path = rtrim($path, '/') ?: '/';

        // Check routes
        $componentName = $this->registry->getByRoute($path);
        if ($componentName !== null) {
            return $componentName;
        }

        // Check query parameter
        if (isset($_GET['component'])) {
            return $_GET['component'];
        }

        // Default to first registered component
        $all = $this->registry->all();
        return $all ? array_key_first($all) : null;
    }

    /**
     * Check if this is an AJAX action request.
     */
    private function isActionRequest(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'POST'
            && isset($_SERVER['HTTP_X_USEPHP_ACTION']);
    }

    /**
     * Handle an AJAX action request.
     */
    private function handleAction(string $componentName): void
    {
        header('Content-Type: application/json');

        $input = $this->getJsonInput();

        if (!isset($input['action'])) {
            echo json_encode(['error' => 'Missing action']);
            return;
        }

        $action = Action::fromArray($input['action']);
        $state = ComponentState::getInstance($componentName);

        // Apply the action
        if ($action->type === 'setState') {
            $index = $action->payload['index'] ?? 0;
            $value = $action->payload['value'] ?? null;
            $state->setState($index, $value);
        }

        // Re-render the component
        $html = $this->doRenderComponent($componentName, false);

        echo json_encode(['html' => $html]);
    }

    /**
     * Render the full page with layout.
     */
    private function renderPage(string $componentName): void
    {
        $content = $this->doRenderComponent($componentName);

        $layoutCallback = $this->layouts[$this->layout] ?? $this->layouts['default'];
        $title = ucfirst($componentName);

        echo $layoutCallback($content, $title, $this->jsPath);
    }

    /**
     * Render a component.
     */
    private function doRenderComponent(string $componentName, bool $withWrapper = true): string
    {
        $component = $this->registry->create($componentName);

        if ($component === null) {
            return '';
        }

        $state = ComponentState::getInstance($componentName);
        ComponentState::reset();

        if ($component instanceof BaseComponent) {
            $component->setComponentState($state);
        }

        $element = $component->render();

        $renderer = new Renderer($componentName);

        if ($withWrapper) {
            return sprintf(
                '<div data-usephp-component="%s">%s</div>',
                htmlspecialchars($componentName, ENT_QUOTES, 'UTF-8'),
                $renderer->renderElement($element)
            );
        }

        return $renderer->renderElement($element);
    }

    /**
     * Get JSON input from the request.
     *
     * @return array<string, mixed>
     */
    private function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');

        if ($raw === false || $raw === '') {
            return [];
        }

        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
    }

    /**
     * Register the default layout.
     */
    private function registerDefaultLayout(): void
    {
        $this->layouts['default'] = function (string $content, string $title, string $jsPath): string {
            return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} - usePHP</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
        }
        [data-usephp-loading="true"] {
            opacity: 0.7;
            pointer-events: none;
        }
    </style>
</head>
<body>
    {$content}
    <script src="{$jsPath}"></script>
</body>
</html>
HTML;
        };
    }

    /**
     * Get the component registry.
     */
    public function getRegistry(): ComponentRegistry
    {
        return $this->registry;
    }
}
