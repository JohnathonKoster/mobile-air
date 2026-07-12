<?php

namespace Native\Mobile\Edge\Inspector;

use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\ElementRegistry;
use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\Edge\TailwindParser;

/** Opt-in metadata and style overrides for element inspectors. */
final class ElementInspector
{
    private static bool $enabled = false;

    /** @var array<string, array<int, string>> */
    private static array $styleOverrides = [];

    private static ?string $activeScope = null;

    public static function enabled(): bool
    {
        return self::$enabled;
    }

    public static function enable(bool $enabled = true): void
    {
        self::$enabled = $enabled;
    }

    public static function setActiveScope(?string $scope): void
    {
        self::$activeScope = $scope;
    }

    public static function setStyleOverride(string $scope, int $nodeId, string $classes): void
    {
        self::$styleOverrides[$scope][$nodeId] = $classes;
    }

    public static function removeStyleOverride(string $scope, int $nodeId): void
    {
        unset(self::$styleOverrides[$scope][$nodeId]);

        if (empty(self::$styleOverrides[$scope])) {
            unset(self::$styleOverrides[$scope]);
        }
    }

    public static function resetStyleOverrides(): void
    {
        self::$styleOverrides = [];
    }

    /**
     * @param  array<string, mixed>  $layout
     * @param  array<string, mixed>  $style
     * @param  array<string, mixed>  $props
     */
    public static function decorate(Element $element, int $id, array &$layout, array &$style, array &$props): void
    {
        if (! self::$enabled) {
            return;
        }

        self::applyStyleOverride($element, $id, $layout, $style, $props);

        $classes = $element->inspectorClassString();
        if ($classes !== null) {
            $props['_dbg_classes'] = $classes;
        }

        if (($activeOverride = self::scopedStyleOverride($id)) !== null) {
            $props['_dbg_classes_active'] = $activeOverride;
        }

        $source = $element->inspectorSourceLocation();
        if ($source !== null) {
            $props['_dbg_src'] = $source;
        }

        foreach ($element->inspectorHandlerMetadata() as $name => $value) {
            if ($value !== null) {
                $props[$name] = $value;
            }
        }

        foreach ($element->getRuntimeData() as $name => $value) {
            $props['_dbg_rt_'.$name] = $value;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $layout
     * @param  array<string, mixed>  $style
     * @param  array<string, mixed>  $props
     */
    public static function decorateStreaming(
        string $type,
        int $id,
        ?string $authoredClasses,
        array $attributes,
        array &$layout,
        array &$style,
        array &$props,
    ): void {
        if (! self::$enabled) {
            return;
        }

        $override = self::scopedStyleOverride($id);
        if ($override !== null) {
            $authored = self::resolveStreamingClassBuckets($type, $authoredClasses ?? '');
            $target = self::resolveStreamingClassBuckets($type, $override);
            $layout = self::replaceAuthoredBucket($layout, $authored['layout'], $target['layout']);
            $style = self::replaceAuthoredBucket($style, $authored['style'], $target['style']);
            $props = self::replaceAuthoredBucket($props, $authored['props'], $target['props']);
            $props['_dbg_classes_active'] = $override;
        }

        if ($authoredClasses !== null && trim($authoredClasses) !== '') {
            $props['_dbg_classes'] = trim($authoredClasses);
        }

        foreach (['_press' => '_dbg_press', '_longPress' => '_dbg_long_press', 'ref' => '_dbg_ref'] as $source => $target) {
            if (isset($attributes[$source]) && is_string($attributes[$source]) && $attributes[$source] !== '') {
                $props[$target] = $attributes[$source];
            }
        }
    }

    private static function scopedStyleOverride(int $id): ?string
    {
        if (self::$activeScope === null) {
            return null;
        }

        $override = self::$styleOverrides[self::$activeScope][$id] ?? null;

        return is_string($override) ? $override : null;
    }

    /**
     * @param  array<string, mixed>  $layout
     * @param  array<string, mixed>  $style
     * @param  array<string, mixed>  $props
     */
    private static function applyStyleOverride(Element $element, int $id, array &$layout, array &$style, array &$props): void
    {
        $override = self::scopedStyleOverride($id);

        if ($override === null) {
            return;
        }

        $authored = $element->resolveInspectorClassBuckets($element->inspectorClassString() ?? '');
        $target = $element->resolveInspectorClassBuckets($override);

        $layout = self::replaceAuthoredBucket($layout, $authored['layout'], $target['layout']);
        $style = self::replaceAuthoredBucket($style, $authored['style'], $target['style']);
        $props = self::replaceAuthoredBucket($props, $authored['props'], $target['props']);
    }

    /** @return array{layout: array, style: array, props: array} */
    private static function resolveStreamingClassBuckets(string $type, string $classes): array
    {
        if (trim($classes) === '') {
            return ['layout' => [], 'style' => [], 'props' => []];
        }

        $element = ElementRegistry::resolve($type);
        if ($element instanceof Element) {
            return $element->resolveInspectorClassBuckets($classes);
        }

        $attributes = TailwindParser::parse($classes);

        return [
            'layout' => NativeElementCollector::buildLayoutArray($attributes),
            'style' => NativeElementCollector::buildStyleArray($attributes),
            'props' => NativeElementCollector::buildDarkProps($attributes)
                + NativeElementCollector::buildAnimationProps($attributes),
        ];
    }

    /**
     * Remove authored values only when they have not been overwritten explicitly.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $authored
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    private static function replaceAuthoredBucket(array $current, array $authored, array $target): array
    {
        foreach ($authored as $key => $value) {
            if (array_key_exists($key, $current) && $current[$key] === $value) {
                unset($current[$key]);
            }
        }

        return array_merge($current, $target);
    }
}
