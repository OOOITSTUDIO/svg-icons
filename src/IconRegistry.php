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

    public static function content(string $name): string
    {
        $content = file_get_contents(self::path($name));

        if ($content === false) {
            throw new \RuntimeException("Unable to read icon '{$name}'");
        }

        return $content;
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
