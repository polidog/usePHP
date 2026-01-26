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
 * Minimal JS for partial updates, falls back to full page reload.
 */
class UsePHP
{
    private static ?self $instance = null;

    private ComponentRegistry $registry;
    private ?string $currentComponent = null;
    private string $layout = 'default';
    private string $jsPath = '/usephp.js';

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
     * @param callable(string $content, string $title, string $jsPath): string $callback
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
    public static function run(string $componentName): void
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
    private function handleRequest(string $componentName): void
    {
        // Handle POST action (form submission)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_usephp_action'])) {
            $this->handleFormAction();
            return;
        }

        if (!$this->registry->has($componentName)) {
            http_response_code(404);
            echo "Component not found: {$componentName}";
            return;
        }

        $this->currentComponent = $componentName;

        // Render the full page
        $this->renderPage($componentName);
    }

    /**
     * Handle form action submission.
     */
    private function handleFormAction(): void
    {
        $componentName = $_POST['_usephp_component'] ?? null;
        $actionJson = $_POST['_usephp_action'] ?? null;
        $isPartial = isset($_SERVER['HTTP_X_USEPHP_PARTIAL']);

        if ($componentName === null || $actionJson === null) {
            http_response_code(400);
            echo 'Invalid action request';
            return;
        }

        if (!$this->registry->has($componentName)) {
            http_response_code(404);
            echo "Component not found: {$componentName}";
            return;
        }

        // Parse and execute the action
        try {
            $actionData = json_decode($actionJson, true, 512, JSON_THROW_ON_ERROR);
            $action = Action::fromArray($actionData);

            $state = ComponentState::getInstance($componentName);

            if ($action->type === 'setState') {
                $index = $action->payload['index'] ?? 0;
                $value = $action->payload['value'] ?? null;
                $state->setState($index, $value);
            }
        } catch (\JsonException $e) {
            http_response_code(400);
            echo 'Invalid action data';
            return;
        }

        // Partial update (AJAX) - return only component HTML
        if ($isPartial) {
            echo $this->doRenderComponentPartial($componentName);
            return;
        }

        // Full page - PRG pattern
        $redirectUrl = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
        header('Location: ' . $redirectUrl, true, 303);
        exit;
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
     * Render a component with wrapper.
     */
    private function doRenderComponent(string $componentName): string
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

        $renderer = new Renderer($componentName);

        return $renderer->render(fn() => $component->render());
    }

    /**
     * Render a component without wrapper (for partial updates).
     */
    private function doRenderComponentPartial(string $componentName): string
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

        $renderer = new Renderer($componentName);

        return $renderer->renderPartial(fn() => $component->render());
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
        [aria-busy="true"] {
            opacity: 0.6;
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
