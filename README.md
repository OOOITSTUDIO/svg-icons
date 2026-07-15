# oooitstudio/svg-icons

Общая библиотека SVG-иконок для проектов OOOITSTUDIO.  
PHP ≥ 8.1.

## Структура

```
icons/
  arrows/
    linear/
      arrow-left.svg
  social/
    bold/
      ...
  call/
    linear/
      ...
src/
  IconRegistry.php
```

Иерархия: **категория → стиль → файл**.

Имя иконки = путь относительно `icons/` без расширения:

- файл `icons/arrows/linear/arrow-left.svg`
- имя `arrows/linear/arrow-left`

## Установка в проект

Подключите пакет из внутреннего репозитория организации (Satis / GitLab / Packagist private) и добавьте зависимость:

```bash
composer require oooitstudio/svg-icons
```

## Использование

```php
use OOOITStudio\SvgIcons\IconRegistry;

// Абсолютный путь к файлу
$path = IconRegistry::path('arrows/linear/arrow-left');

// Сырой SVG без обёртки
$svg = IconRegistry::content('arrows/linear/arrow-left');

// То же через точку вместо слэша
$svg = IconRegistry::content('arrows.linear.arrow-left');

// С размером / классом — SVG в <span class="icon-wrapper">
echo IconRegistry::content('arrows/linear/arrow-left', [
    'size' => 24,
    'class' => 'my-icon',
]);

echo IconRegistry::content('arrows/linear/arrow-left', [
    'width' => 18,
    'height' => 18,
    'style' => ['color' => '#333'],
    'wrapperOptions' => ['data-icon' => 'arrow-left'],
]);

IconRegistry::exists('arrows/linear/arrow-left'); // bool

// Все иконки: ["arrows/linear/arrow-left", "social/bold/...", ...]
IconRegistry::list();

// По категории / категории+стилю
IconRegistry::listByCategory('arrows');
IconRegistry::listByCategory('arrows/linear');

// По стилю во всех категориях
IconRegistry::listByStyle('linear');
IconRegistry::listByStyle('bold');

// Папки
IconRegistry::categories();            // ["arrows", "call", "social", ...]
IconRegistry::categories('arrows');    // ["linear"]
IconRegistry::styles();                // ["bold", "linear", ...]
IconRegistry::styles('social');        // ["bold"]
```

Если иконка не найдена, `path()` и `content()` бросают `RuntimeException`.

## Разработка через Docker

Composer на машине не обязателен — достаточно Docker Desktop.

```bash
# Проверить composer.json
bin/composer.sh validate

# Установить зависимости и сгенерировать autoload
bin/composer.sh install

# Пересобрать optimized autoload
bin/composer.sh build

# Shell внутри контейнера
bin/composer.sh shell

# Любая команда composer
bin/composer.sh show
```

Эквивалент напрямую:

```bash
docker compose run --rm composer validate --strict --no-check-publish
docker compose run --rm composer dump-autoload -o
```

## Добавление иконок

1. Положите `.svg` в `icons/{категория}/{стиль}/`.
2. Обращайтесь по относительному пути без расширения (`arrows/linear/arrow-left`).
3. Версию пакета задавайте git-тегами (`v0.1.0`, `v1.0.0`, …).
