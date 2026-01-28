<?php

declare(strict_types=1);

namespace Polidog\UsePhp;

use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\ComponentInterface;
use Polidog\UsePhp\Component\ComponentRegistry;
use Polidog\UsePhp\Runtime\Action;
use Polidog\UsePhp\Runtime\ComponentId;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\Runtime\Renderer;
use Polidog\UsePhp\Runtime\Snapshot;
use Polidog\UsePhp\Snapshot\SnapshotSerializer;
use Polidog\UsePhp\Snapshot\SnapshotVerificationException;
use Polidog\UsePhp\Storage\StorageType;

/**
 * Main application class for usePHP.
 * Minimal JS for partial updates, falls back to full page reload.
 */
final class UsePHP
{
    private static ?self $instance = null;

    private ComponentRegistry $registry;
    private ?SnapshotSerializer $snapshotSerializer = null;

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
     *
     * @param string $componentName The registered component name
     * @param string|null $key Optional explicit key for the component instance
     */
    public static function render(string $componentName, ?string $key = null): string
    {
        $instance = self::getInstance();
        return $instance->doRenderComponent($componentName, $key);
    }

    /**
     * Create a component Element (without rendering to HTML).
     *
     * Use this when you want to compose multiple components using H class,
     * then render the entire tree with renderElement().
     *
     * @param string $componentName The registered component name
     * @param string|null $key Optional explicit key for the component instance
     */
    public static function createElement(string $componentName, ?string $key = null): Element
    {
        $instance = self::getInstance();
        return $instance->doCreateElement($componentName, $key);
    }

    /**
     * Render an Element tree to HTML.
     *
     * Use this to render Element trees created with createElement() and H class.
     */
    public static function renderElement(Element $element): string
    {
        $instance = self::getInstance();
        return $instance->doRenderElement($element);
    }

    /**
     * Configure the snapshot serializer with a secret key.
     *
     * @param string $secretKey The secret key for snapshot verification
     */
    public static function setSnapshotSecret(string $secretKey): self
    {
        $instance = self::getInstance();
        $instance->snapshotSerializer = new SnapshotSerializer($secretKey);
        return $instance;
    }

    /**
     * Get the snapshot serializer.
     */
    public function getSnapshotSerializer(): SnapshotSerializer
    {
        if ($this->snapshotSerializer === null) {
            $this->snapshotSerializer = new SnapshotSerializer();
        }
        return $this->snapshotSerializer;
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
    private function doHandleAction(): string
    {
        $instanceId = $_POST['_usephp_component'] ?? null;
        $actionJson = $_POST['_usephp_action'] ?? null;
        $snapshotJson = $_POST['_usephp_snapshot'] ?? null;
        $isPartial = isset($_SERVER['HTTP_X_USEPHP_PARTIAL']);

        if ($instanceId === null || $actionJson === null) {
            http_response_code(400);
            return 'Invalid action request';
        }

        // Extract component name from instanceId (e.g., "Counter#0" => "Counter")
        $componentName = explode('#', $instanceId)[0];

        // Check if this is a registered class-based component
        $isRegisteredComponent = $this->registry->has($componentName);

        // For function components (not in registry), use session storage
        $storageType = $isRegisteredComponent
            ? $this->registry->getStorageType($componentName)
            : StorageType::Session;

        // Parse and execute the action
        try {
            $actionData = json_decode($actionJson, true, 512, JSON_THROW_ON_ERROR);
            $action = Action::fromArray($actionData);

            // Handle snapshot storage - restore state from snapshot
            if ($storageType === StorageType::Snapshot && $snapshotJson !== null) {
                $snapshot = $this->getSnapshotSerializer()->deserialize($snapshotJson);
                $state = ComponentState::fromSnapshot($snapshot);
            } else {
                // Use instanceId for state to match the correct component instance
                $state = ComponentState::getInstance($instanceId, $storageType);
            }

            if ($action->type === 'setState') {
                $index = $action->payload['index'] ?? 0;
                $value = $action->payload['value'] ?? null;
                $state->setState($index, $value);
            }
        } catch (\JsonException $e) {
            http_response_code(400);
            return 'Invalid action data';
        } catch (SnapshotVerificationException $e) {
            http_response_code(400);
            return 'Invalid snapshot';
        }

        // Partial update (AJAX) - return only component HTML
        if ($isPartial && $isRegisteredComponent) {
            return $this->doRenderComponentPartialWithInstanceId($instanceId, $componentName);
        }

        // Full page - PRG pattern (for both class components and function components)
        $redirectUrl = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
        header('Location: ' . $redirectUrl, true, 303);
        exit;
    }

    /**
     * Create a component Element with wrapper.
     */
    private function doCreateElement(string $componentName, ?string $key = null): Element
    {
        $component = $this->registry->create($componentName);

        if ($component === null) {
            return new Element('div', [], []);
        }

        $instanceId = RenderContext::nextInstanceId($componentName, $key);
        $storageType = $this->registry->getStorageType($componentName);
        $state = ComponentState::getInstance($instanceId, $storageType);
        ComponentState::reset();

        if ($component instanceof BaseComponent) {
            $component->setComponentState($state);
        }

        // Get the element from component
        $innerElement = $component->render();

        // Build wrapper props
        $props = ['data-usephp' => $instanceId];

        // Add snapshot if using snapshot storage
        if ($storageType === StorageType::Snapshot) {
            $snapshot = $state->createSnapshot();
            $snapshotJson = $this->getSnapshotSerializer()->serialize($snapshot);
            $props['data-usephp-snapshot'] = $snapshotJson;
        }

        return new Element('div', $props, [$innerElement]);
    }

    /**
     * Render an Element tree to HTML.
     */
    private function doRenderElement(Element $element): string
    {
        $renderer = new Renderer('_root_', $this->getSnapshotSerializer());
        return $renderer->renderElement($element);
    }

    /**
     * Render a component with wrapper.
     */
    private function doRenderComponent(string $componentName, ?string $key = null): string
    {
        $component = $this->registry->create($componentName);

        if ($component === null) {
            return '';
        }

        // Start a new render pass
        RenderContext::beginRender();

        $instanceId = RenderContext::nextInstanceId($componentName, $key);
        $storageType = $this->registry->getStorageType($componentName);
        $state = ComponentState::getInstance($instanceId, $storageType);
        ComponentState::reset();

        if ($component instanceof BaseComponent) {
            $component->setComponentState($state);
        }

        $renderer = new Renderer(
            $instanceId,
            $storageType === StorageType::Snapshot ? $this->getSnapshotSerializer() : null,
            $storageType,
        );

        return $renderer->render(fn() => $component->render());
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

        $renderer = new Renderer(
            $instanceId,
            $storageType === StorageType::Snapshot ? $this->getSnapshotSerializer() : null,
            $storageType,
        );

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
