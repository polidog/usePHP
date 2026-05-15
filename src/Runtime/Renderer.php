<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Runtime;

use Polidog\UsePhp\Snapshot\SnapshotSerializer;
use Polidog\UsePhp\Storage\StorageType;

/**
 * Renders Element tree to HTML string.
 * Supports partial updates with minimal JavaScript.
 */
final class Renderer
{
    public const DEFAULT_DEFER_PREFIX = '/_defer';


    private const SELF_CLOSING_TAGS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    /**
     * JSX-style prop names → HTML/SVG attribute names.
     *
     * PSX and H::xxx() preserve JSX casing on the PHP side (`class` and `for`
     * are PHP reserved words and can't appear as named arguments), so the
     * Renderer is responsible for normalising the names when serialising HTML.
     *
     * The table is modelled after react-dom's `possibleStandardNames`: HTML
     * attributes drop the camelCase to a flat lowercase form (`tabIndex` →
     * `tabindex`), SVG attributes are hyphenated (`strokeWidth` →
     * `stroke-width`), and XLink/XML namespace attributes use a colon
     * (`xlinkHref` → `xlink:href`). Attributes that are canonical as-is
     * (`viewBox`, `preserveAspectRatio`, `gradientUnits`, data-*, aria-*,
     * any plain HTML attribute) are intentionally absent — lookups fall back
     * to the original key.
     */
    private const JSX_HTML_ATTR_MAP = [
        // HTML attributes
        'acceptCharset' => 'accept-charset',
        'accessKey' => 'accesskey',
        'allowFullScreen' => 'allowfullscreen',
        'autoCapitalize' => 'autocapitalize',
        'autoComplete' => 'autocomplete',
        'autoCorrect' => 'autocorrect',
        'autoFocus' => 'autofocus',
        'autoPlay' => 'autoplay',
        'autoSave' => 'autosave',
        'cellPadding' => 'cellpadding',
        'cellSpacing' => 'cellspacing',
        'charSet' => 'charset',
        'classID' => 'classid',
        'className' => 'class',
        'colSpan' => 'colspan',
        'contentEditable' => 'contenteditable',
        'contextMenu' => 'contextmenu',
        'controlsList' => 'controlslist',
        'crossOrigin' => 'crossorigin',
        'dateTime' => 'datetime',
        'dirName' => 'dirname',
        'encType' => 'enctype',
        'enterKeyHint' => 'enterkeyhint',
        'fetchPriority' => 'fetchpriority',
        'formAction' => 'formaction',
        'formEncType' => 'formenctype',
        'formMethod' => 'formmethod',
        'formNoValidate' => 'formnovalidate',
        'formTarget' => 'formtarget',
        'frameBorder' => 'frameborder',
        'hrefLang' => 'hreflang',
        'htmlFor' => 'for',
        'httpEquiv' => 'http-equiv',
        'imageSizes' => 'imagesizes',
        'imageSrcSet' => 'imagesrcset',
        'inputMode' => 'inputmode',
        'isMap' => 'ismap',
        'itemID' => 'itemid',
        'itemProp' => 'itemprop',
        'itemRef' => 'itemref',
        'itemScope' => 'itemscope',
        'itemType' => 'itemtype',
        'keyParams' => 'keyparams',
        'keyType' => 'keytype',
        'marginHeight' => 'marginheight',
        'marginWidth' => 'marginwidth',
        'maxLength' => 'maxlength',
        'mediaGroup' => 'mediagroup',
        'minLength' => 'minlength',
        'noModule' => 'nomodule',
        'noValidate' => 'novalidate',
        'playsInline' => 'playsinline',
        'popoverTarget' => 'popovertarget',
        'popoverTargetAction' => 'popovertargetaction',
        'radioGroup' => 'radiogroup',
        'readOnly' => 'readonly',
        'referrerPolicy' => 'referrerpolicy',
        'rowSpan' => 'rowspan',
        'spellCheck' => 'spellcheck',
        'srcDoc' => 'srcdoc',
        'srcLang' => 'srclang',
        'srcSet' => 'srcset',
        'tabIndex' => 'tabindex',
        'useMap' => 'usemap',

        // SVG attributes that get hyphenated
        'accentHeight' => 'accent-height',
        'alignmentBaseline' => 'alignment-baseline',
        'arabicForm' => 'arabic-form',
        'baselineShift' => 'baseline-shift',
        'capHeight' => 'cap-height',
        'clipPath' => 'clip-path',
        'clipRule' => 'clip-rule',
        'colorInterpolation' => 'color-interpolation',
        'colorInterpolationFilters' => 'color-interpolation-filters',
        'colorProfile' => 'color-profile',
        'colorRendering' => 'color-rendering',
        'dominantBaseline' => 'dominant-baseline',
        'enableBackground' => 'enable-background',
        'fillOpacity' => 'fill-opacity',
        'fillRule' => 'fill-rule',
        'floodColor' => 'flood-color',
        'floodOpacity' => 'flood-opacity',
        'fontFamily' => 'font-family',
        'fontSize' => 'font-size',
        'fontSizeAdjust' => 'font-size-adjust',
        'fontStretch' => 'font-stretch',
        'fontStyle' => 'font-style',
        'fontVariant' => 'font-variant',
        'fontWeight' => 'font-weight',
        'glyphName' => 'glyph-name',
        'glyphOrientationHorizontal' => 'glyph-orientation-horizontal',
        'glyphOrientationVertical' => 'glyph-orientation-vertical',
        'horizAdvX' => 'horiz-adv-x',
        'horizOriginX' => 'horiz-origin-x',
        'imageRendering' => 'image-rendering',
        'letterSpacing' => 'letter-spacing',
        'lightingColor' => 'lighting-color',
        'markerEnd' => 'marker-end',
        'markerMid' => 'marker-mid',
        'markerStart' => 'marker-start',
        'overlinePosition' => 'overline-position',
        'overlineThickness' => 'overline-thickness',
        'paintOrder' => 'paint-order',
        'panose1' => 'panose-1',
        'pointerEvents' => 'pointer-events',
        'renderingIntent' => 'rendering-intent',
        'shapeRendering' => 'shape-rendering',
        'stopColor' => 'stop-color',
        'stopOpacity' => 'stop-opacity',
        'strikethroughPosition' => 'strikethrough-position',
        'strikethroughThickness' => 'strikethrough-thickness',
        'strokeDasharray' => 'stroke-dasharray',
        'strokeDashoffset' => 'stroke-dashoffset',
        'strokeLinecap' => 'stroke-linecap',
        'strokeLinejoin' => 'stroke-linejoin',
        'strokeMiterlimit' => 'stroke-miterlimit',
        'strokeOpacity' => 'stroke-opacity',
        'strokeWidth' => 'stroke-width',
        'textAnchor' => 'text-anchor',
        'textDecoration' => 'text-decoration',
        'textRendering' => 'text-rendering',
        'transformOrigin' => 'transform-origin',
        'underlinePosition' => 'underline-position',
        'underlineThickness' => 'underline-thickness',
        'unicodeBidi' => 'unicode-bidi',
        'unicodeRange' => 'unicode-range',
        'unitsPerEm' => 'units-per-em',
        'vAlphabetic' => 'v-alphabetic',
        'vHanging' => 'v-hanging',
        'vIdeographic' => 'v-ideographic',
        'vMathematical' => 'v-mathematical',
        'vectorEffect' => 'vector-effect',
        'vertAdvY' => 'vert-adv-y',
        'vertOriginX' => 'vert-origin-x',
        'vertOriginY' => 'vert-origin-y',
        'wordSpacing' => 'word-spacing',
        'writingMode' => 'writing-mode',
        'xHeight' => 'x-height',

        // XLink / XML namespace attributes
        'xlinkActuate' => 'xlink:actuate',
        'xlinkArcrole' => 'xlink:arcrole',
        'xlinkHref' => 'xlink:href',
        'xlinkRole' => 'xlink:role',
        'xlinkShow' => 'xlink:show',
        'xlinkTitle' => 'xlink:title',
        'xlinkType' => 'xlink:type',
        'xmlBase' => 'xml:base',
        'xmlLang' => 'xml:lang',
        'xmlSpace' => 'xml:space',
        'xmlnsXlink' => 'xmlns:xlink',
    ];

    private string $componentId;
    private ?SnapshotSerializer $snapshotSerializer;
    private ?StorageType $storageType;
    private string $deferPrefix;

    public function __construct(
        string $componentId,
        ?SnapshotSerializer $snapshotSerializer = null,
        ?StorageType $storageType = null,
        string $deferPrefix = self::DEFAULT_DEFER_PREFIX,
    ) {
        $this->componentId = $componentId;
        $this->snapshotSerializer = $snapshotSerializer;
        $this->storageType = $storageType;
        $this->deferPrefix = $deferPrefix;
    }

    /**
     * Render a component function with wrapper.
     *
     * @param callable(): Element $component
     */
    public function render(callable $component): string
    {
        $state = ComponentState::getInstance($this->componentId, $this->storageType);
        ComponentState::reset();

        $element = $component();
        $inner = $this->renderElement($element);

        // Build attributes
        $attrs = sprintf('data-usephp="%s"', htmlspecialchars($this->componentId, ENT_QUOTES, 'UTF-8'));

        // Add snapshot attribute if using snapshot storage
        if ($this->shouldEmbedSnapshot($state)) {
            $snapshotJson = $this->serializeSnapshot($state);
            $attrs .= sprintf(' data-usephp-snapshot=\'%s\'', htmlspecialchars($snapshotJson, ENT_QUOTES, 'UTF-8'));
        }

        // Wrap with component container for partial updates
        return sprintf('<div %s>%s</div>', $attrs, $inner);
    }

    /**
     * Render a component without wrapper (for partial updates).
     *
     * @param callable(): Element $component
     */
    public function renderPartial(callable $component): string
    {
        $state = ComponentState::getInstance($this->componentId, $this->storageType);
        ComponentState::reset();

        $element = $component();
        $inner = $this->renderElement($element);

        // For snapshot storage, include hidden field with updated snapshot
        if ($this->shouldEmbedSnapshot($state)) {
            $snapshotJson = $this->serializeSnapshot($state);
            $inner .= sprintf(
                '<input type="hidden" name="_usephp_snapshot" value="%s" data-usephp-snapshot-update />',
                htmlspecialchars($snapshotJson, ENT_QUOTES, 'UTF-8')
            );
        }

        return $inner;
    }

    /**
     * Render an element to HTML.
     */
    public function renderElement(Element|string $element): string
    {
        if (is_string($element)) {
            return htmlspecialchars($element, ENT_QUOTES, 'UTF-8');
        }

        // Handle Fragment
        if ($element->type === 'Fragment') {
            return $this->renderChildren($element->children);
        }

        // Handle deferred placeholder
        if ($element->type === '__defer__') {
            return $this->renderDeferred($element);
        }

        $tag = $element->type;
        $props = $element->props;
        $hasAction = isset($props['wire:click']);

        // If element has an action, wrap it in a form
        if ($hasAction) {
            return $this->renderWithForm($element);
        }

        $attributes = $this->renderAttributes($props);

        // Self-closing tags
        if (in_array($tag, self::SELF_CLOSING_TAGS, true)) {
            return "<{$tag}{$attributes} />";
        }

        $children = $this->renderChildren($element->children);

        return "<{$tag}{$attributes}>{$children}</{$tag}>";
    }

    /**
     * Render an element wrapped in a form for action handling.
     */
    private function renderWithForm(Element $element): string
    {
        $action = $element->props['wire:click'];
        $tag = $element->type;

        // Remove wire:click from props
        $props = $element->props;
        unset($props['wire:click']);

        // For button, make it a submit button
        if ($tag === 'button') {
            $props['type'] = 'submit';
        }

        $attributes = $this->renderAttributes($props);
        $children = $this->renderChildren($element->children);

        // Build the form with hidden action data
        $actionJson = htmlspecialchars($action->toJson(), ENT_QUOTES, 'UTF-8');
        // Prefer componentId from Action, fall back to Renderer's componentId
        $componentId = $action->componentId ?? $this->componentId;
        $componentIdEscaped = htmlspecialchars($componentId, ENT_QUOTES, 'UTF-8');

        $innerElement = in_array($tag, self::SELF_CLOSING_TAGS, true)
            ? "<{$tag}{$attributes} />"
            : "<{$tag}{$attributes}>{$children}</{$tag}>";

        // Add snapshot hidden field if using snapshot storage
        // Use action's componentId to get the correct state from cache
        $snapshotField = '';
        $state = ComponentState::getInstance($componentId);
        if ($this->shouldEmbedSnapshot($state)) {
            $snapshotJson = $this->serializeSnapshot($state);
            $snapshotField = sprintf(
                '<input type="hidden" name="_usephp_snapshot" value="%s" />',
                htmlspecialchars($snapshotJson, ENT_QUOTES, 'UTF-8')
            );
        }

        // data-usephp-form enables JS enhancement, falls back to normal form if no JS
        return <<<HTML
            <form method="post" data-usephp-form style="display:inline;">
            <input type="hidden" name="_usephp_component" value="{$componentIdEscaped}" />
            <input type="hidden" name="_usephp_action" value="{$actionJson}" />
            {$snapshotField}{$innerElement}
            </form>
            HTML;
    }

    /**
     * Render element attributes.
     *
     * @param array<string, mixed> $props
     */
    private function renderAttributes(array $props): string
    {
        $attributes = [];

        foreach ($props as $key => $value) {
            // Skip wire:* attributes (handled separately)
            if (str_starts_with($key, 'wire:')) {
                continue;
            }

            $attrName = self::JSX_HTML_ATTR_MAP[$key] ?? $key;

            // Handle boolean attributes
            if (is_bool($value)) {
                if ($value) {
                    $attributes[] = $attrName;
                }
                continue;
            }

            // Skip non-scalar values
            if (!is_scalar($value)) {
                continue;
            }

            // Handle regular attributes
            $escapedValue = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            $attributes[] = sprintf('%s="%s"', $attrName, $escapedValue);
        }

        return $attributes ? ' ' . implode(' ', $attributes) : '';
    }

    /**
     * Render children elements.
     *
     * @param array<Element|string|int|float> $children
     */
    private function renderChildren(array $children): string
    {
        $html = '';

        foreach ($children as $child) {
            if ($child instanceof Element) {
                $html .= $this->renderElement($child);
            } elseif (is_string($child)) {
                $html .= htmlspecialchars($child, ENT_QUOTES, 'UTF-8');
            } elseif (is_numeric($child)) {
                $html .= (string) $child;
            }
        }

        return $html;
    }

    /**
     * Render a deferred placeholder. Embeds the GET URL to a dedicated
     * defer endpoint; usephp.js fetches it after page load and swaps in the
     * response. The URL is name-addressed, so each deferred component can
     * carry its own Cache-Control policy and is independently CDN-cacheable.
     */
    private function renderDeferred(Element $element): string
    {
        $name = (string) ($element->props['__name'] ?? '');
        /** @var array<string, mixed> $params */
        $params = $element->props['__params'] ?? [];
        $fallback = $element->props['__fallback'] ?? null;

        if ($name === '' || preg_match('/^[A-Za-z0-9_-]+$/', $name) !== 1) {
            throw new \RuntimeException(
                "Deferred component name must be URL-safe (`[A-Za-z0-9_-]+`), got: '$name'",
            );
        }

        $queryParams = [];
        foreach ($params as $key => $value) {
            if ($key === '') {
                throw new \RuntimeException(
                    "Deferred component '$name' params must use non-empty string keys.",
                );
            }
            if ($value === null) {
                continue;
            }
            if (!is_scalar($value)) {
                throw new \RuntimeException(
                    "Deferred component '$name' param '$key' must be scalar; got " . get_debug_type($value),
                );
            }
            $queryParams[$key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }

        $url = $this->deferPrefix . '/' . rawurlencode($name);
        if ($queryParams !== []) {
            $url .= '?' . http_build_query($queryParams);
        }

        $fallbackHtml = $fallback instanceof Element || is_string($fallback)
            ? $this->renderElement($fallback)
            : '';

        return sprintf(
            '<div data-usephp-defer-url="%s">%s</div>',
            htmlspecialchars($url, ENT_QUOTES, 'UTF-8'),
            $fallbackHtml,
        );
    }

    /**
     * Check if snapshot should be embedded in the output.
     */
    private function shouldEmbedSnapshot(ComponentState $state): bool
    {
        // State must actually be using snapshot storage to create snapshots
        return $state->isSnapshotStorage();
    }

    /**
     * Serialize the current state as a snapshot JSON.
     */
    private function serializeSnapshot(ComponentState $state): string
    {
        $snapshot = $state->createSnapshot();

        if ($this->snapshotSerializer !== null) {
            return $this->snapshotSerializer->serialize($snapshot);
        }

        // Use default serializer without secret key
        return $snapshot->toJson();
    }
}
