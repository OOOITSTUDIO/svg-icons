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
     * Список всех иконок вида "linear/arrows/arrow-left", "bold/social/facebook" и т.д.
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
     * Иконки из категории любого уровня вложенности.
     * Например: listByCategory('linear') или listByCategory('linear/arrows')
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
     * Категории (папки) на указанном уровне.
     * categories() → ["bold", "linear"]
     * categories('linear') → ["arrows", "call", ...]
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
     * Нормализация имени: "linear.arrows.arrow-left" → "linear/arrows/arrow-left".
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
