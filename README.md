# METSIS Drupal Module

![Drupal](https://img.shields.io/badge/Drupal-11-0678BE?style=for-the-badge&logo=drupal&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?style=for-the-badge&logo=php&logoColor=white)

[![ESLint](https://img.shields.io/github/actions/workflow/status/magnarem/metsis_drupal/quality.yml?job=ESLint&label=ESLint&style=flat-square)](https://github.com/magnarem/metsis_drupal/actions/workflows/quality.yml)
[![Prettier](https://img.shields.io/github/actions/workflow/status/magnarem/metsis_drupal/quality.yml?job=Prettier%20Check&label=Prettier&style=flat-square)](https://github.com/magnarem/metsis_drupal/actions/workflows/quality.yml)
[![PHPStan](https://img.shields.io/github/actions/workflow/status/magnarem/metsis_drupal/quality.yml?job=PHPStan&label=PHPStan&style=flat-square)](https://github.com/magnarem/metsis_drupal/actions/workflows/quality.yml)
[![PHPCS](https://img.shields.io/github/actions/workflow/status/magnarem/metsis_drupal/quality.yml?job=PHPCS&label=PHPCS&style=flat-square)](https://github.com/magnarem/metsis_drupal/actions/workflows/quality.yml)
[![PHPUnit](https://img.shields.io/github/actions/workflow/status/magnarem/metsis_drupal/quality.yml?job=PHPUnit&label=PHPUnit&style=flat-square)](https://github.com/magnarem/metsis_drupal/actions/workflows/quality.yml)

## Table of contents

1. [Overview](#1-overview)
2. [Runtime and architecture](#2-runtime-and-architecture)
3. [Installation](#3-installation)
4. [Functionality and documentation](#4-functionality-and-documentation)
5. [Development and testing](#5-development-and-testing)
6. [Project context](#6-project-context)

## 1. Overview

`metsis_drupal` provides the interactive dataset search frontend for the
MET Norway Scientific Information System (METSIS). It combines Solr-backed
discovery with geographic and temporal filtering, metadata dialogs, vocabulary
labels, metadata export, and dataset visualisation.

## 2. Runtime and architecture

- **Drupal:** `^11.4 || ^12`; primary development/CI runtime is PHP **8.4**.
- **Search:** Views, Search API, Solr, Facets, Better Exposed Filters, and autocomplete.
- **UI:** Drupal render arrays, Twig/SDC, core HTMX, and Leaflet previews.
- **Interactive maps:** Vite applications using Preact and OpenLayers.
- **Data:** search results are external Solr documents, not loaded node entities.

The module source lives at the repository root; `web/` is the disposable local
Drupal test site. See the [architecture guide](docs/architecture.md),
[module dependencies](metsis_drupal.info.yml), and [Composer manifest](composer.json).

## 3. Installation

Follow the [installation and configuration guide](docs/installation.md) for
dependencies, enabling the module, Solr setup, shipped autocomplete, and upgrades.
For a local checkout, use the [DDEV development guide](docs/development/README.md#1-ddev-setup).

After installation, configure **Configuration → METNO → METSIS Drupal**
at `/admin/config/metno/metsis-drupal` and open `/metsis/search`.

## 4. Functionality and documentation

| Topic                                                              | Guide                                              |
| ------------------------------------------------------------------ | -------------------------------------------------- |
| Custom Views, query modes, sidebar layout, and exposed-form blocks | [Search and Views](docs/search-and-views.md)       |
| Result maps, bbox heights, WMS, and map isolation                  | [Maps and visualisation](docs/maps.md)             |
| Metadata dialogs, exports, vocabularies, and landing pages         | [Metadata](docs/metadata.md)                       |
| Vocabulary service integration examples                            | [MetVocabService](docs/met-vocab-service-usage.md) |
| Services, templates, frontend libraries, and SDC contracts         | [Architecture](docs/architecture.md)               |
| NetCDF requests and in-dialog login                                | [NBS Extensions](modules/nbs_extensions/README.md) |

Optional submodules include `dynamic_landing_pages` for dataset landing pages
and `nbs_extensions` for product-aware NetCDF on-demand requests.

Metadata details and dynamic landing pages share an accessible
[Data center section](docs/metadata.md#data-center) with name fallbacks and
optional short-name subtext.

The shared [search layout](docs/search-and-views.md#2-layout-and-templates)
adapts to the theme's available content width. Result cards and temporal
controls reflow within narrow columns, retaining usable mobile abstracts
and collection actions.

## 5. Development and testing

See [development and CI](docs/development/README.md) for DDEV commands, builds,
PHP/frontend checks, and browser-test prerequisites. Local VS Code/Copilot
storage is documented separately in the [chat-history guide](docs/development/vscode-history.md).

The [quality workflow](.github/workflows/quality.yml) runs ESLint, Prettier,
PHPStan, PHPCS, and PHPUnit, with ChromeDriver infrastructure for browser tests.

## 6. Project context

About the data catalogue: <https://adc.met.no/about>.
