# oooitstudio/svg-icons

Общая библиотека SVG-иконок для проектов OOOITSTUDIO.  
PHP ≥ 8.1.

## Структура

```
icons/
  bold/
    social/
      ...
  linear/
    arrows/
      arrow-left.svg
    ...
src/
  IconRegistry.php
```

Имя иконки = путь относительно `icons/` без расширения:

- файл `icons/linear/arrows/arrow-left.svg`
- имя `linear/arrows/arrow-left`

## Установка в проект

Подключите пакет из внутреннего репозитория организации (Satis / GitLab / Packagist private) и добавьте зависимость:

```bash
composer require oooitstudio/svg-icons
```

## Использование

```php
use OOOITStudio\SvgIcons\IconRegistry;

// Абсолютный путь к файлу
$path = IconRegistry::path('linear/arrows/arrow-left');

// Содержимое SVG
$svg = IconRegistry::content('linear/arrows/arrow-left');

// То же через точку вместо слэша
$svg = IconRegistry::content('linear.arrows.arrow-left');

IconRegistry::exists('linear/arrows/arrow-left'); // bool

// Все иконки: ["bold/social/...", "linear/arrows/arrow-left", ...]
IconRegistry::list();

// По категории любого уровня
IconRegistry::listByCategory('linear');
IconRegistry::listByCategory('linear/arrows');

// Папки на уровне
IconRegistry::categories();           // ["bold", "linear"]
IconRegistry::categories('linear');   // ["arrows", "call", ...]
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

1. Положите `.svg` в нужную папку внутри `icons/`.
2. Обращайтесь по относительному пути без расширения.
3. Версию пакета задавайте git-тегами (`v0.1.0`, `v1.0.0`, …).
