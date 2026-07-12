<?php

namespace Native\Mobile\Edge\Concerns;

use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\Edge\TailwindParser;

/** @internal */
trait HasInspectorMetadata
{
    /** @var array{classes?: string, source?: string, runtime?: array<string, string>} */
    protected array $inspectorMetadata = [];

    public function rememberClassString(string $classes): static
    {
        $classes = trim($classes);

        if ($classes === '') {
            return $this;
        }

        $captured = $this->inspectorMetadata['classes'] ?? null;

        if ($captured === null) {
            $this->inspectorMetadata['classes'] = $classes;

            return $this;
        }

        if (str_contains(' '.$captured.' ', ' '.$classes.' ')) {
            return $this;
        }

        $this->inspectorMetadata['classes'] = $captured.' '.$classes;

        return $this;
    }

    public function rememberSourceLocation(?string $path): static
    {
        if ($path !== null && $path !== '') {
            $this->inspectorMetadata['source'] = $path;
        }

        return $this;
    }

    public function runtimeData(string $name, string $value): static
    {
        $this->inspectorMetadata['runtime'][$name] = $value;

        return $this;
    }

    /** @return array<string, string> */
    public function getRuntimeData(): array
    {
        return $this->inspectorMetadata['runtime'] ?? [];
    }

    public function inspectorClassString(): ?string
    {
        return $this->inspectorMetadata['classes'] ?? null;
    }

    public function inspectorSourceLocation(): ?string
    {
        return $this->inspectorMetadata['source'] ?? null;
    }

    /** @return array<string, string|null> */
    public function inspectorHandlerMetadata(): array
    {
        return [
            '_dbg_press' => $this->pressMethod,
            '_dbg_long_press' => $this->longPressMethod,
            '_dbg_ref' => $this->elementRef,
        ];
    }

    /** @return array{layout: array, style: array, props: array} */
    public function resolveInspectorClassBuckets(string $classes): array
    {
        if (trim($classes) === '') {
            return ['layout' => [], 'style' => [], 'props' => []];
        }

        $scratch = clone $this;
        $scratch->layout = [];
        $scratch->style = [];
        $scratch->extraProps = [];
        $scratch->darkProps = [];
        $scratch->children = [];

        $attrs = TailwindParser::parse($classes);
        NativeElementCollector::applyLayout($scratch, $attrs);
        NativeElementCollector::applyStyle($scratch, $attrs);
        NativeElementCollector::applyElementProps($scratch, $attrs);

        return [
            'layout' => $scratch->layout,
            'style' => $scratch->style,
            'props' => array_merge($scratch->extraProps, NativeElementCollector::buildDarkProps($attrs)),
        ];
    }
}
