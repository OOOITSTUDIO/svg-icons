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

Вручную:

1. Положите `.svg` в `icons/{категория}/{стиль}/`.
2. Обращайтесь по относительному пути без расширения (`arrows/linear/arrow-left`).
3. Версии — semver `vMAJOR.MINOR.PATCH` (git-теги). После merge в `main` CI сам делает bump и пушит в Packagist (по умолчанию **patch**).

## Синхронизация с Figma

Дизайнер правит icon pack в Figma. GitHub Action по cron или вручную выгружает SVG, меняет заданные цвета на `currentColor` и открывает pull request.

### Именование в Figma

В Figma используйте порядок `Стиль / Категория / Имя`, а в репозитории файл будет сохранён как `категория/стиль/имя.svg`.

```text
Linear / users / user          -> users/linear/user.svg
Bold / social / telegram       -> social/bold/telegram.svg
Linear / network / window-frame -> network/linear/window-frame.svg
```

Структура фиксированная: ровно 3 сегмента.  
Экспортируются только узлы типа `COMPONENT`.

### Секреты и переменные репозитория

GitHub -> Settings -> Secrets and variables:

- `FIGMA_TOKEN` — secret, Personal Access Token Figma
- `FIGMA_FILE_KEY` — secret, ключ файла из URL вида `figma.com/design/<FILE_KEY>/...`
- `FIGMA_PAGE_NAME` — variable, опционально, имя страницы; по умолчанию `Icons`
- `FIGMA_NODE_ID` — variable, опционально, id конкретного узла/фрейма вместо поиска по имени
- `PACKAGIST_USERNAME` — secret, логин Packagist
- `PACKAGIST_TOKEN` — secret, API token Packagist (Update API / GitHub hook token)
- `PACKAGIST_API_URL` — variable, опционально; по умолчанию `https://packagist.org/api/update-package`, для Private Packagist — `https://packagist.com/api/update-package`

Для вашего текущего файла:

- `FIGMA_FILE_KEY`: `kDXgobDuxpuACvizC3oH1U`
- `FIGMA_NODE_ID`: `0:1`

Цвета для замены на `currentColor` задаются в `scripts/figma-sync/config.json` в поле `colorsToCurrentColor`.

### Локальный запуск

```bash
export FIGMA_TOKEN=figd_...
export FIGMA_FILE_KEY=xxxxxxxx
cd scripts/figma-sync
node sync.mjs --dry-run
node sync.mjs
```

Флаг `--keep-orphans` отключает удаление локальных SVG, которых больше нет в Figma.

### Workflow

Файл `.github/workflows/sync-figma-icons.yml`:

- запускается по cron каждый понедельник в 06:00 UTC
- поддерживает ручной запуск через `workflow_dispatch`
- при изменениях создаёт PR в ветку `chore/sync-figma-icons`

После merge PR в `main` workflow `.github/workflows/packagist-push.yml` (аналог `npm version`):

- по умолчанию **patch** (`v0.1.8` → `v0.1.9`)
- в заголовке PR / коммите можно написать `#minor` или `#major`
- пушит тег и дергает Packagist Update API

| Ситуация | Bump |
|---|---|
| Новые/изменённые иконки из Figma | patch (автоматически) |
| Обратно совместимое изменение API (`IconRegistry` и т.п.) | `#minor` в заголовке PR |
| Ломающее изменение API | `#major` в заголовке PR |
| Вручную | Actions → Packagist push → выбрать patch/minor/major |

Ручной релиз: Actions → Packagist push → Run workflow.
