<?php

namespace OOOITStudio\SvgIcons;

class IconRegistry
{
    private static function basePath(): string
    {
        return __DIR__ . '/../icons';
    }

    private static function resolvedBasePath(): string
    {
        $base = realpath(self::basePath());

        if ($base === false || !is_dir($base)) {
            throw new \RuntimeException('Icons directory not found');
        }

        return $base;
    }

    public static function path(string $name): string
    {
        $relative = self::normalize($name);
        $path = self::resolvedBasePath() . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relative) . '.svg';
        $realPath = realpath($path);

        if ($realPath === false || !self::isInsideBase($realPath)) {
            throw new \RuntimeException("Icon '{$name}' not found");
        }

        return $realPath;
    }

    /**
     * Содержимое SVG.
     *
     * Без опций (или без size/width/height/class) — оригинальный файл.
     * С size/width/height/class — SVG без фиксированных размеров внутри <span>.
     *
     * Опции:
     * - size: int|string — width и height обёртки сразу
     * - width / height: int|string — размеры обёртки
     * - class: string|array — CSS-класс(ы) обёртки (по умолчанию icon-wrapper)
     * - style: string|array — дополнительные стили обёртки
     * - wrapperOptions: array — доп. HTML-атрибуты обёртки
     *
     * @param array<string, mixed> $options
     */
    public static function content(string $name, array $options = []): string
    {
        $svg = file_get_contents(self::path($name));

        if ($svg === false) {
            throw new \RuntimeException("Unable to read icon '{$name}'");
        }

        $needAdaptive = isset($options['size'])
            || isset($options['width'])
            || isset($options['height'])
            || isset($options['class']);

        if (!$needAdaptive) {
            return $svg;
        }

        return self::wrapAdaptive($svg, $options);
    }

    public static function exists(string $name): bool
    {
        try {
            self::path($name);
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * Список всех иконок вида "arrows/linear/arrow-left", "social/bold/facebook" и т.д.
     *
     * @return list<string>
     */
    public static function list(): array
    {
        try {
            $base = self::resolvedBasePath();
        } catch (\RuntimeException) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $base,
                \FilesystemIterator::SKIP_DOTS
            )
        );

        $icons = [];
        $prefixLength = strlen($base) + 1;

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'svg') {
                continue;
            }

            $relative = substr($file->getPathname(), $prefixLength);
            $relative = str_replace('\\', '/', $relative);
            $icons[] = preg_replace('/\.svg$/i', '', $relative) ?? $relative;
        }

        sort($icons);

        return $icons;
    }

    /**
     * Иконки из категории (и опционально стиля).
     * Например: listByCategory('arrows') или listByCategory('arrows/linear')
     *
     * @return list<string>
     */
    public static function listByCategory(string $category): array
    {
        $prefix = self::normalize($category) . '/';

        return array_values(array_filter(
            self::list(),
            static fn(string $icon): bool => str_starts_with($icon, $prefix)
        ));
    }

    /**
     * Иконки одного стиля во всех категориях (второй сегмент пути).
     * Например: listByStyle('linear') → ["arrows/linear/...", "call/linear/...", ...]
     *
     * @return list<string>
     */
    public static function listByStyle(string $style): array
    {
        $style = self::normalize($style);

        if (str_contains($style, '/')) {
            throw new \InvalidArgumentException('Style must be a single path segment');
        }

        return array_values(array_filter(
            self::list(),
            static function (string $icon) use ($style): bool {
                $parts = explode('/', $icon);

                return ($parts[1] ?? null) === $style;
            }
        ));
    }

    /**
     * Категории (папки) на указанном уровне.
     * categories() → ["arrows", "call", "social", ...]
     * categories('arrows') → ["linear"]
     *
     * @return list<string>
     */
    public static function categories(?string $parent = null): array
    {
        try {
            $base = self::resolvedBasePath();
        } catch (\RuntimeException) {
            return [];
        }

        $dir = $base;
        if ($parent !== null && $parent !== '') {
            $relative = self::normalize($parent);
            $dir = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $realDir = realpath($dir);

            if ($realDir === false || !is_dir($realDir) || !self::isInsideBase($realDir, $base)) {
                return [];
            }

            $dir = $realDir;
        }

        $categories = [];
        foreach (new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->isDir()) {
                $categories[] = $item->getFilename();
            }
        }

        sort($categories);

        return $categories;
    }

    /**
     * Стили: styles() — уникальные по всему пакету; styles('arrows') — внутри категории.
     *
     * @return list<string>
     */
    public static function styles(?string $category = null): array
    {
        if ($category !== null && $category !== '') {
            return self::categories($category);
        }

        $styles = [];
        foreach (self::categories() as $cat) {
            foreach (self::categories($cat) as $style) {
                $styles[$style] = true;
            }
        }

        $result = array_keys($styles);
        sort($result);

        return $result;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function wrapAdaptive(string $svg, array $options): string
    {
        $svg = preg_replace_callback(
            '/<svg\b[^>]*>/i',
            static function (array $matches): string {
                return preg_replace(
                    '/\s+(width|height)\s*=\s*["\'][^"\']*["\']/i',
                    '',
                    $matches[0]
                ) ?? $matches[0];
            },
            $svg,
            1
        ) ?? $svg;

        $svg = preg_replace(
            '/<svg\b/i',
            '<svg style="display:block; width:100%; height:100%;"',
            $svg,
            1
        ) ?? $svg;

        $wrapperStyle = [];

        if (isset($options['size'])) {
            $sizeValue = self::cssSize($options['size']);
            $wrapperStyle['width'] = $sizeValue;
            $wrapperStyle['height'] = $sizeValue;
        } else {
            if (isset($options['width'])) {
                $wrapperStyle['width'] = self::cssSize($options['width']);
            }
            if (isset($options['height'])) {
                $wrapperStyle['height'] = self::cssSize($options['height']);
            }
        }

        if (!empty($options['style'])) {
            $wrapperStyle = array_merge($wrapperStyle, self::parseStyle($options['style']));
        }

        $wrapperStyle['display'] = $wrapperStyle['display'] ?? 'inline-block';
        $wrapperStyle['flex-shrink'] = $wrapperStyle['flex-shrink'] ?? '0';

        $defaultClass = 'icon-wrapper';
        $userClass = $options['class'] ?? null;

        if ($userClass === null || $userClass === '') {
            $wrapperClass = $defaultClass;
        } elseif (is_array($userClass)) {
            $wrapperClass = trim($defaultClass . ' ' . implode(' ', $userClass));
        } else {
            $wrapperClass = trim($defaultClass . ' ' . $userClass);
        }

        $attributes = [
            'class' => $wrapperClass,
            'style' => self::styleToString($wrapperStyle),
        ];

        if (isset($options['wrapperOptions']) && is_array($options['wrapperOptions'])) {
            $attributes = array_merge($attributes, $options['wrapperOptions']);
        }

        return self::tag('span', $svg, $attributes);
    }

    private static function cssSize(mixed $value): string
    {
        return is_numeric($value) ? $value . 'px' : (string) $value;
    }

    /**
     * @param string|array<string, string> $style
     * @return array<string, string>
     */
    private static function parseStyle(string|array $style): array
    {
        if (is_array($style)) {
            $result = [];
            foreach ($style as $key => $value) {
                $result[(string) $key] = (string) $value;
            }

            return $result;
        }

        $result = [];
        foreach (explode(';', $style) as $pair) {
            $parts = explode(':', $pair, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);
            if ($key !== '') {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param array<string, string> $style
     */
    private static function styleToString(array $style): string
    {
        $parts = [];
        foreach ($style as $prop => $val) {
            $parts[] = $prop . ': ' . $val;
        }

        return implode('; ', $parts);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private static function tag(string $tag, string $content, array $attributes): string
    {
        $attrString = '';
        foreach ($attributes as $name => $value) {
            if ($value === null || $value === false) {
                continue;
            }

            if (is_array($value)) {
                $value = implode(' ', $value);
            }

            $attrString .= ' ' . $name . '="'
                . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '"';
        }

        return '<' . $tag . $attrString . '>' . $content . '</' . $tag . '>';
    }

    /**
     * Нормализация имени: "arrows.linear.arrow-left" → "arrows/linear/arrow-left".
     * Защита от path traversal (..).
     */
    private static function normalize(string $name): string
    {
        $name = str_replace(['\\', '.'], ['/', '/'], trim($name, "/ \t\n\r\0\x0B"));
        $parts = array_values(array_filter(
            explode('/', $name),
            static fn(string $part): bool => $part !== '' && $part !== '.' && $part !== '..'
        ));

        if ($parts === []) {
            throw new \InvalidArgumentException('Icon name must not be empty');
        }

        return implode('/', $parts);
    }

    private static function isInsideBase(string $path, ?string $base = null): bool
    {
        $base ??= self::resolvedBasePath();
        $prefix = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($path . DIRECTORY_SEPARATOR, $prefix);
    }
}
