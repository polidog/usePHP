<?php

declare(strict_types=1);

namespace Polidog\UsePhp;

use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\ComponentInterface;
use Polidog\UsePhp\Component\ComponentRegistry;
use Polidog\UsePhp\Runtime\Action;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\Runtime\Renderer;

/**
 * Main application class for usePHP.
 * Minimal JS for partial updates, falls back to full page reload.
 */
final class UsePHP
{
    private static ?self $instance = null;

    private ComponentRegistry $registry;

    private function __construct()
    {
        $this->registry = new ComponentRegistry();
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
     * Render a component and return HTML.
     */
    public static function render(string $componentName): string
    {
        $instance = self::getInstance();
        return $instance->doRenderComponent($componentName);
    }

    /**
     * Handle a POST action and return the partial HTML.
     * Returns null if not a valid action request.
     */
    public static function handleAction(): ?string
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['_usephp_action'])) {
            return null;
        }

        $instance = self::getInstance();
        return $instance->doHandleAction();
    }

    /**
     * Handle form action submission.
     */
    private function doHandleAction(): ?string
    {
        $instanceId = $_POST['_usephp_component'] ?? null;
        $actionJson = $_POST['_usephp_action'] ?? null;
        $isPartial = isset($_SERVER['HTTP_X_USEPHP_PARTIAL']);

        if ($instanceId === null || $actionJson === null) {
            http_response_code(400);
            return 'Invalid action request';
        }

        // Extract component name from instanceId (e.g., "Counter#0" => "Counter")
        $componentName = explode('#', $instanceId)[0];

        if (!$this->registry->has($componentName)) {
            http_response_code(404);
            return "Component not found: {$componentName}";
        }

        // Parse and execute the action
        try {
            $actionData = json_decode($actionJson, true, 512, JSON_THROW_ON_ERROR);
            $action = Action::fromArray($actionData);

            // Use instanceId for state to match the correct component instance
            $storageType = $this->registry->getStorageType($componentName);
            $state = ComponentState::getInstance($instanceId, $storageType);

            if ($action->type === 'setState') {
                $index = $action->payload['index'] ?? 0;
                $value = $action->payload['value'] ?? null;
                $state->setState($index, $value);
            }
        } catch (\JsonException $e) {
            http_response_code(400);
            return 'Invalid action data';
        }

        // Partial update (AJAX) - return only component HTML
        if ($isPartial) {
            return $this->doRenderComponentPartialWithInstanceId($instanceId, $componentName);
        }

        // Full page - PRG pattern
        $redirectUrl = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
        header('Location: ' . $redirectUrl, true, 303);
        exit;
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

        // Start a new render pass
        RenderContext::beginRender();

        $instanceId = RenderContext::nextInstanceId($componentName);
        $storageType = $this->registry->getStorageType($componentName);
        $state = ComponentState::getInstance($instanceId, $storageType);
        ComponentState::reset();

        if ($component instanceof BaseComponent) {
            $component->setComponentState($state);
        }

        $renderer = new Renderer($instanceId);

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

        // Start a new render pass
        RenderContext::beginRender();

        $instanceId = RenderContext::nextInstanceId($componentName);
        $storageType = $this->registry->getStorageType($componentName);
        $state = ComponentState::getInstance($instanceId, $storageType);
        ComponentState::reset();

        if ($component instanceof BaseComponent) {
            $component->setComponentState($state);
        }

        $renderer = new Renderer($instanceId);

        return $renderer->renderPartial(fn() => $component->render());
    }

    /**
     * Render a component partial with a specific instance ID (for form action handling).
     */
    private function doRenderComponentPartialWithInstanceId(string $instanceId, string $componentName): string
    {
        $component = $this->registry->create($componentName);

        if ($component === null) {
            return '';
        }

        $storageType = $this->registry->getStorageType($componentName);
        $state = ComponentState::getInstance($instanceId, $storageType);
        ComponentState::reset();

        if ($component instanceof BaseComponent) {
            $component->setComponentState($state);
        }

        $renderer = new Renderer($instanceId);

        return $renderer->renderPartial(fn() => $component->render());
    }

    /**
     * Get the component registry.
     */
    public function getRegistry(): ComponentRegistry
    {
        return $this->registry;
    }
}
